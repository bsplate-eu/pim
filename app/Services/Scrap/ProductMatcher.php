<?php

namespace App\Services\Scrap;

use App\Models\Product;
use App\Models\Scrap\ScrapProduct;

/**
 * Auto-mapowanie ofert konkurenta ↔ nasze produkty (Argo Scope).
 *
 * U nas JEDEN SKU = kilka modeli aut (np. `00.005` = 9 produktów). Priorytet dopasowania:
 *   1) SKU: herstellernummer ↔ product_code  → zawęża do kandydatów,
 *   2) wśród kandydatów EAN: oferta.ean ↔ produkt.ean  → najpewniejszy wybór,
 *   3) brak/niedopasowany EAN → nazwa: model + rocznik (tokeny z tytułu oferty vs niemiecka nazwa),
 *   + gdy SKU nic nie znajdzie → globalny fallback po EAN.
 *
 * Normalizacja klucza: usuń cudzysłowy, trim, wielkie litery (`"27.202alu"` == `27.202ALU`).
 * NIE obcinamy sufiksu ALU — `06.048` (stal) ≠ `06.048ALU` (aluminium).
 */
class ProductMatcher
{
    /** @return array{checked:int,matched:int,sku_unique:int,sku_by_ean:int,sku_by_name:int,ean_only:int} */
    public function matchSource(string $source): array
    {
        $candidatesByCode = $this->candidatesByCode();
        $byEan = $this->eanIndex();

        $toMatch = ScrapProduct::where('source', $source)
            ->whereNull('product_id')
            ->get(['id', 'herstellernummer', 'ean', 'title']);

        $c = ['sku_unique' => 0, 'sku_by_ean' => 0, 'sku_by_name' => 0, 'ean_only' => 0];

        foreach ($toMatch as $sp) {
            $hn = $this->norm($sp->herstellernummer);
            $ean = $this->norm($sp->ean);

            $pid = null;
            $bucket = null;

            if ($hn !== '' && isset($candidatesByCode[$hn])) {
                $cands = $candidatesByCode[$hn];
                if (count($cands) === 1) {                              // 1) SKU jednoznaczne
                    $pid = $cands[0]['id'];
                    $bucket = 'sku_unique';
                } elseif ($ean !== '' && ($hit = $this->pickByEan($ean, $cands)) !== null) {
                    $pid = $hit;                                        // 2) duplikat → EAN
                    $bucket = 'sku_by_ean';
                } else {
                    $pid = $this->bestByTitle($sp->title, $cands);     // 3) duplikat → nazwa (model+rocznik)
                    $bucket = 'sku_by_name';
                }
            } elseif ($ean !== '' && isset($byEan[$ean])) {            // brak SKU → globalny EAN
                $pid = $byEan[$ean];
                $bucket = 'ean_only';
            }

            if ($pid) {
                ScrapProduct::where('id', $sp->id)->update(['product_id' => $pid, 'match_type' => 'auto']);
                $c[$bucket]++;
            }
        }

        return [
            'checked' => $toMatch->count(),
            'matched' => array_sum($c),
            'sku_unique' => $c['sku_unique'],
            'sku_by_ean' => $c['sku_by_ean'],
            'sku_by_name' => $c['sku_by_name'],
            'ean_only' => $c['ean_only'],
        ];
    }

    /** [normCode => [ ['id'=>int,'ean'=>string,'tokens'=>set<string>], ... ]]. */
    private function candidatesByCode(): array
    {
        $map = [];
        Product::whereNotNull('product_code')->where('product_code', '!=', '')
            ->orderBy('id')
            ->select(['id', 'product_code', 'ean', 'name'])
            ->chunk(1000, function ($chunk) use (&$map) {
                foreach ($chunk as $p) {
                    $k = $this->norm($p->product_code);
                    if ($k !== '') {
                        $map[$k][] = [
                            'id' => $p->id,
                            'ean' => $this->norm($p->ean),
                            'tokens' => $this->tokens($this->nameDe($p->name)),
                        ];
                    }
                }
            });

        return $map;
    }

    /** [normEan => product_id] do globalnego fallbacku; przy duplikatach pierwszy (stabilnie po id). */
    private function eanIndex(): array
    {
        $map = [];
        Product::whereNotNull('ean')->where('ean', '!=', '')
            ->orderBy('id')
            ->select(['id', 'ean'])
            ->chunk(1000, function ($chunk) use (&$map) {
                foreach ($chunk as $p) {
                    $k = $this->norm($p->ean);
                    if ($k !== '' && ! isset($map[$k])) {
                        $map[$k] = $p->id;
                    }
                }
            });

        return $map;
    }

    /** Krok 2: wśród kandydatów SKU znajdź tego z tym samym EAN. */
    private function pickByEan(string $ean, array $cands): ?int
    {
        foreach ($cands as $c) {
            if (($c['ean'] ?? '') === $ean) {
                return $c['id'];
            }
        }

        return null;
    }

    /** Krok 3: najlepszy kandydat po tytule — liczą się tokeny RÓŻNICUJĄCE (model + rocznik), nie wspólny szum. */
    private function bestByTitle(?string $title, array $cands): int
    {
        $tt = $this->tokens((string) $title);

        $common = $cands[0]['tokens'];
        for ($i = 1, $n = count($cands); $i < $n; $i++) {
            $common = array_intersect_key($common, $cands[$i]['tokens']);
        }

        $bestId = $cands[0]['id'];
        $bestScore = -1;
        foreach ($cands as $c) {
            $discriminating = array_diff_key($c['tokens'], $common);
            $score = count(array_intersect_key($tt, $discriminating));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $c['id'];
            }
        }

        return $bestId;
    }

    /** Niemiecka nazwa z JSON matrycy tłumaczeń (fallback pl → pierwsza); obsługuje array i string-JSON. */
    private function nameDe($name): string
    {
        $d = is_array($name) ? $name : json_decode((string) $name, true);
        if (is_array($d)) {
            $v = $d['de'] ?? $d['pl'] ?? reset($d);

            return is_string($v) ? $v : '';
        }

        return (string) $name;
    }

    /** Zbiór tokenów (set): małe litery, alfanumeryczne (litery+cyfry, więc model i rocznik), długość ≥ 2. */
    private function tokens(string $s): array
    {
        $s = preg_replace('/[^a-z0-9äöüß]+/u', ' ', mb_strtolower($s));
        $out = [];
        foreach (preg_split('/\s+/', trim($s)) as $t) {
            if (mb_strlen($t) >= 2) {
                $out[$t] = true;
            }
        }

        return $out;
    }

    /** Normalizacja klucza dopasowania: usuń WSZYSTKIE cudzysłowy, trim, wielkie litery. */
    private function norm(?string $v): string
    {
        if ($v === null) {
            return '';
        }

        return strtoupper(trim(str_replace(['"', "'"], '', $v)));
    }
}
