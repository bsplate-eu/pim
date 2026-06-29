<?php

namespace App\Services\Scrap;

use App\Models\Scrap\ScrapChange;
use App\Models\Scrap\ScrapProduct;
use App\Models\Scrap\ScrapSource;

/**
 * Wspólny silnik „mózg" monitoringu sklepów konkurenta (Argo Scope → Rumuni).
 * Driver per źródło (LokopiClient / RomaniaClient), reszta wspólna: upsert + diff + cennik-staty.
 * Klucz spinający z eBay i PIM: herstellernummer (ArtikelNr/SKU) + EAN — ten sam producent (Scut Protection).
 */
class ShopScrapService
{
    /** Sklepy WWW (eBay ma osobny pipeline przez API). */
    public const SHOPS = [
        'stahl' => ['label' => 'Niemcy (stahl-unterfahrschutz.eu)', 'seller' => 'stahl-unterfahrschutz.eu', 'currency' => 'EUR'],
        'wegry' => ['label' => 'Węgry (motorvedolemezek.com)', 'seller' => 'motorvedolemezek.com', 'currency' => 'HUF'],
        'rumunia' => ['label' => 'Rumunia (scut-motor.ro)', 'seller' => 'scut-motor.ro', 'currency' => 'RON'],
        'francja' => ['label' => 'Francja (protectionsousmoteur.eu)', 'seller' => 'protectionsousmoteur.eu', 'currency' => 'EUR'],
        'czechy' => ['label' => 'Czechy (krytpodmotor.com)', 'seller' => 'krytpodmotor.com', 'currency' => 'EUR'],
        'hiszpania' => ['label' => 'Hiszpania (cubrecarterprotect.es)', 'seller' => 'cubrecarterprotect.es', 'currency' => 'EUR'],
    ];

    public function __construct(private string $source)
    {
        if (! isset(self::SHOPS[$this->source])) {
            throw new \InvalidArgumentException("Nieznany sklep: {$this->source}");
        }
    }

    public static function make(string $source): self
    {
        return new self($source);
    }

    public static function isShop(string $source): bool
    {
        return isset(self::SHOPS[$source]);
    }

    private function clientFor(string $source): ShopClient
    {
        return match ($source) {
            'stahl' => new LokopiClient('https://www.stahl-unterfahrschutz.eu/unterfahrschutz-sitemap', 'EUR'),
            'wegry' => new LokopiClient('https://www.motorvedolemezek.com/motorvedo-sitemap-skid-plate', 'HUF'),
            'rumunia' => new RomaniaClient('https://www.scut-motor.ro'),
            'francja' => new LokopiClient('https://www.protectionsousmoteur.eu/plan-du-site', 'EUR'),
            'czechy' => new LokopiClient('https://www.krytpodmotor.com/sitemap-kryt-pod-motor', 'EUR'),
            'hiszpania' => new SpainClient(),
        };
    }

    /**
     * @param int|null      $limit    ogranicz liczbę produktów (test)
     * @param int           $delayMs  opóźnienie między żądaniami
     * @param callable|null $progress fn(int $done, int $total)
     * @return array{fetched:int,new:int,removed:int,price_up:int,price_down:int,errors:int}
     */
    public function fullSync(?int $limit = null, int $delayMs = 200, ?callable $progress = null): array
    {
        $started = now();
        $cfg = self::SHOPS[$this->source];
        $client = $this->clientFor($this->source);

        $prev = ScrapProduct::where('source', $this->source)
            ->get(['id', 'external_id', 'price', 'is_active'])
            ->keyBy('external_id');
        $prevActive = $prev->where('is_active', true)->count();

        $now = now();
        $new = 0;
        $priceUp = 0;
        $priceDown = 0;
        $fetched = 0;
        $errors = 0;
        $seen = [];

        foreach ($client->products($delayMs, $progress) as $o) {
            // Jeden wadliwy rekord nie może ubić całego pomiaru (stały scrap / cron).
            try {
                $extId = (string) $o['external_id'];
                $newPrice = $o['price'];
                $existing = $prev->get($extId);

                if (! $existing) {
                    $new++;
                    $this->recordChange('new', $o, null, $newPrice, $now);
                } elseif ($newPrice !== null && $existing->price !== null && (float) $existing->price != $newPrice) {
                    if ($newPrice > (float) $existing->price) {
                        $priceUp++;
                        $this->recordChange('price_up', $o, (float) $existing->price, $newPrice, $now);
                    } else {
                        $priceDown++;
                        $this->recordChange('price_down', $o, (float) $existing->price, $newPrice, $now);
                    }
                }

                $rec = ScrapProduct::firstOrNew(['source' => $this->source, 'external_id' => $extId]);
                if (! $rec->exists) {
                    $rec->first_seen = $now;
                }
                $rec->fill([
                    'seller' => $cfg['seller'],
                    'title' => $o['title'],
                    'price' => $newPrice,
                    'currency' => $o['currency'] ?: $cfg['currency'],
                    'herstellernummer' => $o['herstellernummer'],
                    'ean' => $o['ean'],
                    'url' => $o['url'],
                    'raw' => $o['raw'] ?? null,
                    'last_seen' => $now,
                    'is_active' => true,
                ])->save();

                $seen[$extId] = true;
                $fetched++;
                if ($limit !== null && $fetched >= $limit) {
                    break;
                }
            } catch (\Throwable) {
                $errors++;
            }
        }

        // Wycofane: aktywne, brak w bieżącym pomiarze. Pomijamy przy teście (--limit) ORAZ gdy pomiar
        // wygląda na niekompletny (<50% poprzedniego stanu) — nie masakrujemy katalogu po nieudanym crawlu.
        $removed = 0;
        $looksComplete = $prevActive === 0 || $fetched >= (int) ($prevActive * 0.5);
        if ($limit === null && $looksComplete) {
            foreach ($prev as $extId => $p) {
                if (! isset($seen[$extId]) && $p->is_active) {
                    ScrapProduct::where('id', $p->id)->update(['is_active' => false]);
                    $this->recordChange('removed', ['external_id' => $extId], (float) $p->price, null, $now);
                    $removed++;
                }
            }
        }

        ScrapSource::updateOrCreate(['source' => $this->source], [
            'label' => $cfg['label'],
            'last_sync_at' => $now,
            'last_sync_count' => $fetched,
            'prev_offer_count' => $prevActive,
            'last_new_count' => $new,
            'last_removed_count' => $removed,
            'last_price_up' => $priceUp,
            'last_price_down' => $priceDown,
            'last_status' => 'ok',
            'last_error' => $errors > 0 ? "{$errors} rekordów z błędem (pominięte)" : null,
            'last_duration_s' => now()->diffInSeconds($started),
        ]);

        return [
            'fetched' => $fetched,
            'new' => $new,
            'removed' => $removed,
            'price_up' => $priceUp,
            'price_down' => $priceDown,
            'errors' => $errors,
        ];
    }

    private function recordChange(string $type, array $o, ?float $old, ?float $new, $at): void
    {
        ScrapChange::create([
            'source' => $this->source,
            'type' => $type,
            'external_id' => $o['external_id'] ?? null,
            'title' => $o['title'] ?? null,
            'herstellernummer' => $o['herstellernummer'] ?? null,
            'old_price' => $old,
            'new_price' => $new,
            'detected_at' => $at,
        ]);
    }
}
