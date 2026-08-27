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

            $hit = $this->pickForCode($candidatesByCode, $hn, $sp->title, $ean);
            $pid = $hit['id'];
            $bucket = $hit['how'];

            if (! $pid && $ean !== '' && isset($byEan[$ean])) {        // brak SKU → globalny EAN
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

    /** [normCode => [ ['id'=>int,'ean'=>string,'tokens'=>set<string>], ... ]].
     *  PUBLICZNE — tej samej mapy używa moduł eBay (ebay_offers), żeby nie mieć drugiej logiki dopasowania. */
    public function candidatesByCode(): array
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
                            // getRawOriginal, NIE $p->name — Product ma HasTranslations, a app.locale='en'
                            // nie istnieje w matrycy (cs,de,es,fr,pl,sk...), więc $p->name zwraca PUSTY string.
                            // Puste tokeny = zerowy scoring = zawsze pierwszy kandydat (stąd „wszędzie Mazda 3").
                            'tokens' => $this->tokens($this->nameDe($p->getRawOriginal('name'))),
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
    /** Rozstrzygnięcie JEDNEGO kodu na produkt — wspólne dla Scope (scrap_products) i eBay (ebay_offers).
     *  product_code NIE jest unikalny (jedna osłona pasuje do wielu modeli: 13.121 = Mazda 3/6/Atenza/Axela/CX5,
     *  a 18.201 aż do 21 aut), więc sam kod nie wystarcza — przy duplikacie rozstrzyga EAN, a gdy go brak: TYTUŁ.
     *  Zwraca ['id' => ?int, 'how' => 'sku_unique'|'sku_by_ean'|'sku_by_name'|null].
     *  @param array $byCode wynik candidatesByCode() (buduj RAZ przed pętlą — czyta całą tabelę produktów) */
    public function pickForCode(array $byCode, ?string $code, ?string $title, ?string $ean = null): array
    {
        $code = $this->norm($code);
        if ($code === '' || ! isset($byCode[$code])) {
            return ['id' => null, 'how' => null];
        }

        $cands = $byCode[$code];
        if (count($cands) === 1) {                                      // 1) kod jednoznaczny
            return ['id' => $cands[0]['id'], 'how' => 'sku_unique'];
        }

        $ean = $this->norm($ean);
        if ($ean !== '' && ($hit = $this->pickByEan($ean, $cands)) !== null) {
            return ['id' => $hit, 'how' => 'sku_by_ean'];               // 2) duplikat → EAN
        }

        return ['id' => $this->bestByTitle($title, $cands), 'how' => 'sku_by_name']; // 3) duplikat → nazwa
    }

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

    /** Zbiór tokenów (set): małe litery, alfanumeryczne (litery+cyfry, więc model i rocznik).
     *  Próg długości 2 — ALE pojedyncze cyfry przechodzą, bo bywają całą nazwą modelu
     *  (Mazda 3 vs Mazda 6 na wspólnym kodzie 13.121). Bez tego oba mają pusty zbiór
     *  różnicujący i wygrywa kandydat o niższym id. Litery 1-znakowe zostają odsiane jako szum. */
    private function tokens(string $s): array
    {
        $s = preg_replace('/[^a-z0-9äöüß]+/u', ' ', mb_strtolower($s));
        $out = [];
        foreach (preg_split('/\s+/', trim($s)) as $t) {
            if (mb_strlen($t) >= 2 || ctype_digit($t)) {
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
