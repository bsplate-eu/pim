<?php

namespace App\Console\Commands;

use App\Models\Ebay\EbayOffer;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayOAuthService;
use App\Services\Ebay\EbaySellClient;
use App\Services\Ebay\EbayTaxonomyClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * PILOT kType — wysyłka kompatybilności pojazdów na nasze aukcje eBay.
 *
 * Dla wybranych aukcji (zmapowanych na produkt PIM): make/model/lata z atrybutów
 * → dopasowanie modelu do bazy pojazdów eBaya (Taxonomy; generacja rzymska I/II/III
 * czytana z cyfry w tytule aukcji/nazwie produktu) → wpisy {Make, Model, Year} po
 * rocznikach → ReviseFixedPriceItem (eBay sam rozwija na warianty silnikowe).
 *
 * DOMYŚLNIE DRY-RUN (nic nie wysyła). Wysyłka: --apply.
 * Pilot celowo bierze tylko produkty z engine=all (osłona pasuje do wszystkich wersji
 * silnikowych) — dla engine-specific trzeba będzie zawężać po Type, to później.
 *
 *   php artisan ebay:ktype-push --limit=10            # dry-run: pokaż co by poszło
 *   php artisan ebay:ktype-push --limit=10 --apply    # wyślij
 *   php artisan ebay:ktype-push --items=206046060037 --apply
 */
class EbayKtypePush extends Command
{
    protected $signature = 'ebay:ktype-push
        {--items= : konkretne ItemID po przecinku (nadpisuje --limit)}
        {--limit=10 : ile aukcji wziąć}
        {--marketplace=EBAY_DE : rynek}
        {--category=14769 : kategoria do zapytań Taxonomy}
        {--apply : faktycznie wyślij na eBay (bez tego dry-run)}';

    protected $description = 'Pilot kType: wyślij kompatybilność pojazdów (Make/Model/Year) na wybrane aukcje eBay';

    private EbayTaxonomyClient $taxonomy;
    private string $treeId;
    private string $categoryId;
    private array $modelCache = [];
    private array $yearCache = [];

    public function handle(): int
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            $this->error('Konto eBay nie jest połączone (OAuth).');

            return self::FAILURE;
        }

        $marketplace = strtoupper((string) $this->option('marketplace'));
        $this->taxonomy = new EbayTaxonomyClient($settings->client_id, $settings->client_secret);
        $this->treeId = $this->taxonomy->categoryTreeId($marketplace);
        $this->categoryId = (string) $this->option('category');
        $client = new EbaySellClient($settings, new EbayOAuthService($settings));

        $q = EbayOffer::query()
            ->where('listing_status', 'Active')
            ->where('marketplace', $marketplace)
            ->whereNotNull('product_id')
            ->with('product.attributeValues.attribute');

        if ($items = (string) $this->option('items')) {
            $q->whereIn('item_id', array_filter(array_map('trim', explode(',', $items))));
        }

        $offers = $q->get()->unique('item_id')->values();

        // Pilot: tylko engine=all (fitment „wszystkie wersje" jest wtedy jednoznaczny).
        $rows = [];
        foreach ($offers as $offer) {
            $v = $this->vehicleAttrs($offer);
            if (! $v) {
                continue;
            }
            if ($v['engine'] !== 'all' && ! $this->option('items')) {
                continue;
            }
            $rows[] = ['offer' => $offer, 'vehicle' => $v];
            if (! $this->option('items') && count($rows) >= (int) $this->option('limit')) {
                break;
            }
        }

        if ($rows === []) {
            $this->error('Brak pasujących aukcji (aktywne, zmapowane, engine=all).');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'WYSYŁKA' : 'DRY-RUN (nic nie wysyłam — podgląd)') . " — aukcji: " . count($rows));
        $this->newLine();

        $report = [];
        foreach ($rows as $row) {
            $offer = $row['offer'];
            $v = $row['vehicle'];

            $this->line("── {$offer->item_id} | {$offer->title}");
            $this->line("   PIM {$offer->product->product_code}: {$v['make']} / {$v['model']} / {$v['year_start']}–{$v['year_stop']} (gen. z tytułu: " . ($v['generation'] ?? '—') . ')');

            try {
                $resolved = $this->resolveVehicle($v);
            } catch (\Throwable $e) {
                $this->warn('   BŁĄD Taxonomy: ' . $e->getMessage());
                $report[] = ['item_id' => $offer->item_id, 'status' => 'taxonomy_error', 'error' => $e->getMessage()];
                continue;
            }

            if (! $resolved) {
                $this->warn('   NIE DOPASOWANO modelu w bazie pojazdów eBaya — pomijam (do ręcznej decyzji).');
                $report[] = ['item_id' => $offer->item_id, 'status' => 'unmatched', 'vehicle' => $v];
                continue;
            }

            [$ebayMake, $ebayModel, $years] = $resolved;
            $this->info("   → eBay: {$ebayMake} / {$ebayModel} / roczniki: " . implode(', ', $years));

            if ($years === []) {
                $this->warn('   Brak wspólnych roczników — pomijam.');
                $report[] = ['item_id' => $offer->item_id, 'status' => 'no_years', 'vehicle' => $v, 'ebay_model' => $ebayModel];
                continue;
            }

            $compat = array_map(fn ($y) => ['Make' => $ebayMake, 'Model' => $ebayModel, 'Year' => (string) $y], $years);

            if (! $apply) {
                $report[] = ['item_id' => $offer->item_id, 'status' => 'dry_run', 'ebay_make' => $ebayMake, 'ebay_model' => $ebayModel, 'years' => $years];
                continue;
            }

            try {
                $warnings = $client->reviseCompatibility($offer->item_id, $offer->marketplace, $compat);
                foreach ($warnings as $w) {
                    $this->warn('   eBay: ' . $w);
                }
                $this->info('   ✓ wysłano ' . count($compat) . ' wpisów');
                $report[] = ['item_id' => $offer->item_id, 'status' => 'sent', 'ebay_make' => $ebayMake, 'ebay_model' => $ebayModel, 'years' => $years, 'warnings' => $warnings];
            } catch (\Throwable $e) {
                $this->error('   BŁĄD wysyłki: ' . $e->getMessage());
                $report[] = ['item_id' => $offer->item_id, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        $path = 'ebay/ktype-push-' . now()->format('Y-m-d-His') . ($apply ? '' : '-dryrun') . '.json';
        Storage::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();
        $this->info("Raport: storage/app/{$path}");

        return self::SUCCESS;
    }

    /** Atrybuty pojazdu produktu + generacja wyłuskana z tytułu aukcji/nazwy produktu. */
    private function vehicleAttrs(EbayOffer $offer): ?array
    {
        $attrs = [];
        foreach ($offer->product->attributeValues as $av) {
            $slug = $av->attribute->slug;
            if (in_array($slug, ['make', 'model', 'year-start', 'year-stop', 'engine'])) {
                $attrs[$slug] = trim((string) ($av->name['en'] ?? $av->slug));
            }
        }
        if (empty($attrs['make']) || empty($attrs['model']) || empty($attrs['year-start']) || empty($attrs['year-stop'])) {
            return null;
        }

        // Generacja: cyfra po nazwie modelu w tytule aukcji (np. „Grand Vitara 2 (2005-…”) lub nazwie PL.
        $generation = null;
        $modelWords = str_replace('-', ' ', $attrs['model']);
        foreach ([$offer->title, $offer->product->name['pl'] ?? '', $offer->product->name['en'] ?? ''] as $hint) {
            if ($hint && preg_match('/' . preg_quote($modelWords, '/') . '\s+(\d{1,2})\b/i', str_replace('-', ' ', $hint), $m)) {
                $generation = (int) $m[1];
                break;
            }
        }

        return [
            'make' => $attrs['make'],
            'model' => $attrs['model'],
            'year_start' => (int) $attrs['year-start'],
            'year_stop' => (int) $attrs['year-stop'],
            'engine' => strtolower($attrs['engine'] ?? 'all'),
            'generation' => $generation,
        ];
    }

    /** Dopasuj pojazd do bazy eBaya: [Make, Model, lata]. Null gdy niejednoznaczne. */
    private function resolveVehicle(array $v): ?array
    {
        // Marka: wartości Make z bazy, dopasowanie bez wielkości liter (suzuki → Suzuki).
        $makes = $this->modelCache['__makes'] ??= $this->taxonomy->compatibilityPropertyValues($this->treeId, $this->categoryId, 'Make');
        $ebayMake = collect($makes)->first(fn ($m) => $this->norm($m) === $this->norm($v['make']));
        if (! $ebayMake) {
            return null;
        }

        // Modele marki (cache per marka).
        $models = $this->modelCache[$ebayMake] ??= $this->taxonomy->compatibilityPropertyValues($this->treeId, $this->categoryId, 'Model', ['Make' => $ebayMake]);

        // Cel: baza modelu + generacja (rzymska w bazie eBaya: Grand Vitara II ↔ „grand vitara 2”).
        $base = $this->norm($v['model']);
        $target = $v['generation'] ? "{$base} {$v['generation']}" : $base;

        $normalized = collect($models)->mapWithKeys(fn ($m) => [$m => $this->norm($m)]);
        $exact = $normalized->filter(fn ($n) => $n === $target)->keys();

        $candidates = $exact->isNotEmpty()
            ? $exact
            // Bez trafienia wprost: modele zaczynające się od bazy, rozstrzygną roczniki.
            : $normalized->filter(fn ($n) => $n === $base || str_starts_with($n, $base . ' '))->keys();

        // Zawęź po pokryciu roczników: model musi obejmować większość naszego zakresu.
        $best = null;
        $bestCover = 0;
        foreach ($candidates as $model) {
            $years = $this->yearCache[$ebayMake . '|' . $model] ??= array_map('intval', $this->taxonomy->compatibilityPropertyValues($this->treeId, $this->categoryId, 'Year', ['Make' => $ebayMake, 'Model' => $model]));
            $overlap = array_values(array_filter($years, fn ($y) => $y >= $v['year_start'] && $y <= $v['year_stop']));
            $cover = count($overlap) / max(1, $v['year_stop'] - $v['year_start'] + 1);
            if ($cover > $bestCover) {
                $bestCover = $cover;
                $best = [$model, $overlap];
            }
        }

        // Wymagamy sensownego pokrycia zakresu lat — inaczej to zgadywanie, nie dopasowanie.
        if (! $best || $bestCover < 0.6) {
            return null;
        }

        sort($best[1]);

        return [$ebayMake, $best[0], $best[1]];
    }

    /** Normalizacja do porównań: małe litery, myślniki→spacje, rzymskie→arabskie. */
    private function norm(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $s))));
        $roman = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9, 'x' => 10, 'xi' => 11, 'xii' => 12];

        return implode(' ', array_map(
            fn ($w) => isset($roman[$w]) ? (string) $roman[$w] : $w,
            explode(' ', $s)
        ));
    }
}
