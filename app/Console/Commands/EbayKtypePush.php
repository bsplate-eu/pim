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
        {--retry= : ponów aukcje z rejestru o tym statusie (unmatched/no_years/no_platform)}
        {--from-title : pojazd czytaj z TYTUŁU aukcji, nie z atrybutów produktu (bliźniaki badge}
        {--apply : faktycznie wyślij na eBay (bez tego dry-run)}';

    protected $description = 'Pilot kType: wyślij kompatybilność pojazdów (Make/Model/Year) na wybrane aukcje eBay';

    private EbayTaxonomyClient $taxonomy;
    private string $treeId;
    private string $categoryId;
    private array $modelCache = [];
    private array $yearCache = [];
    private array $platformCache = [];

    /** Nasza nazwa marki → nazwa w bazie pojazdów eBaya (obie znormalizowane). */
    private const MAKE_ALIASES = [
        'volkswagen' => 'vw',
    ];

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

        // Status „Active" w bazie bywa martwy (aukcja skończyła się, wiersz zostaje z last_seen
        // sprzed godzin) — żywa aukcja = widziana w OSTATNIM przebiegu synca (cron co godzinę).
        $lastSync = EbayOffer::query()->where('marketplace', $marketplace)->max('last_seen');
        $q = EbayOffer::query()
            ->where('listing_status', 'Active')
            ->where('last_seen', '>=', \Carbon\Carbon::parse($lastSync)->subMinutes(15))
            ->where('marketplace', $marketplace)
            ->whereNotNull('product_id')
            ->with('product.attributeValues.attribute');

        if ($items = (string) $this->option('items')) {
            $q->whereIn('item_id', array_filter(array_map('trim', explode(',', $items))));
        }

        $offers = $q->get()->unique('item_id')->values();

        // Rejestr obrobionych aukcji (wysłane + terminalnie pominięte) — kolejne paczki
        // biorą tylko nowe. --items wymusza ponowną obróbkę (np. po poprawce dopasowania).
        $pushedPath = 'ebay/ktype-pushed.json';
        $pushed = Storage::exists($pushedPath)
            ? (json_decode(Storage::get($pushedPath), true) ?: [])
            : [];

        // Ponowienie po poprawce resolvera: zdejmij z rejestru wskazany status, żeby te aukcje
        // wróciły do puli (np. --retry=unmatched po dodaniu aliasów marek).
        if ($retry = (string) $this->option('retry')) {
            $before = count($pushed);
            $pushed = array_filter($pushed, fn ($s) => $s !== $retry);
            $this->info("Ponawiam status [{$retry}]: " . ($before - count($pushed)) . ' aukcji wraca do puli.');
        }

        // Pilot: tylko engine=all (fitment „wszystkie wersje" jest wtedy jednoznaczny).
        $rows = [];
        $mismatched = [];
        foreach ($offers as $offer) {
            if (isset($pushed[$offer->item_id]) && ! $this->option('items')) {
                continue;
            }
            $v = $this->vehicleAttrs($offer);
            if (! $v) {
                continue;
            }
            if ($v['engine'] !== 'all' && ! $this->option('items')) {
                continue;
            }

            // Tryb bliźniaków: pojazd bierzemy z tytułu aukcji. Aukcja „Toyota Proace" ma dostać
            // fitment Toyoty (tego szuka kupujący), choć produkt w PIM to citroen/jumpy.
            if ($this->option('from-title')) {
                $fromTitle = $this->vehicleFromTitle($offer, $v);
                if (! $fromTitle) {
                    $mismatched[] = ['item_id' => $offer->item_id, 'status' => 'title_unparsed', 'title' => $offer->title];
                    continue;
                }
                $v = $fromTitle + ['engine' => $v['engine']];
                $rows[] = ['offer' => $offer, 'vehicle' => $v];
                if (! $this->option('items') && count($rows) >= (int) $this->option('limit')) {
                    break;
                }
                continue;
            }

            // Strażnik: marka i model z atrybutów PIM muszą występować w tytule aukcji.
            // Łapie bliźniaki badge'owe (aukcja „Suzuki SX4" ↔ produkt fiat/sedici) i błędne
            // mapowania SKU (aukcja „Vitara" ↔ produkt s-cross) — fitment z cudzej marki to szkodnik.
            $title = $this->norm($offer->title);
            if (! str_contains($title, $this->norm($v['make'])) || ! str_contains($title, $this->norm($v['model']))) {
                $mismatched[] = ['item_id' => $offer->item_id, 'status' => 'title_mismatch', 'title' => $offer->title, 'vehicle' => $v];
                continue;
            }
            $rows[] = ['offer' => $offer, 'vehicle' => $v];
            if (! $this->option('items') && count($rows) >= (int) $this->option('limit')) {
                break;
            }
        }

        if ($mismatched !== []) {
            $this->warn('Pominięte (do ręcznej decyzji): ' . count($mismatched));
            foreach ($mismatched as $m) {
                // title_unparsed nie niesie pojazdu (nie dało się go wyczytać z tytułu).
                $vehicle = isset($m['vehicle']) ? "  ↔  {$m['vehicle']['make']}/{$m['vehicle']['model']}" : '';
                $this->line("   [{$m['status']}] {$m['item_id']} | {$m['title']}{$vehicle}");
            }
            $this->newLine();
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

            [$ebayMake, $ebayModels, $years] = $resolved;
            $this->info("   → eBay: {$ebayMake} / " . implode(' + ', $ebayModels) . ' / roczniki: ' . implode(', ', $years));

            if ($years === []) {
                $this->warn('   Brak wspólnych roczników — pomijam.');
                $report[] = ['item_id' => $offer->item_id, 'status' => 'no_years', 'vehicle' => $v, 'ebay_model' => $ebayModels];
                continue;
            }

            // DE wymaga minimum Make+Model+Platform (sprawdzone na żywo: same Make/Model/Year
            // odrzuca). Wpisy per model × platforma × rocznik; kombinacje spoza bazy eBay pominie
            // (raportując ostrzeżeniem), reszta się zapisuje.
            $compat = [];
            $taxonomyError = null;
            foreach ($ebayModels as $ebayModel) {
                try {
                    $platforms = $this->platformCache[$ebayMake . '|' . $ebayModel]
                        ??= $this->taxonomy->compatibilityPropertyValues($this->treeId, $this->categoryId, 'Platform', ['Make' => $ebayMake, 'Model' => $ebayModel]);
                } catch (\Throwable $e) {
                    // Błąd Taxonomy nie może wywrócić całej paczki — aukcja wraca w kolejnym przebiegu.
                    $taxonomyError = $e->getMessage();
                    break;
                }
                if ($platforms === []) {
                    continue;
                }
                $this->line("   {$ebayModel} → platformy: " . implode(' | ', $platforms));
                foreach ($platforms as $platform) {
                    foreach ($years as $y) {
                        $compat[] = ['Make' => $ebayMake, 'Model' => $ebayModel, 'Platform' => $platform, 'Year' => (string) $y];
                    }
                }
            }

            if ($taxonomyError !== null) {
                $this->warn('   BŁĄD Taxonomy (platformy): ' . $taxonomyError);
                $report[] = ['item_id' => $offer->item_id, 'status' => 'taxonomy_error', 'error' => $taxonomyError];
                continue;
            }
            if ($compat === []) {
                $this->warn('   Brak platform w bazie eBaya — pomijam.');
                $report[] = ['item_id' => $offer->item_id, 'status' => 'no_platform', 'vehicle' => $v, 'ebay_model' => $ebayModels];
                continue;
            }

            if (! $apply) {
                $report[] = ['item_id' => $offer->item_id, 'status' => 'dry_run', 'ebay_make' => $ebayMake, 'ebay_model' => $ebayModels, 'years' => $years, 'entries' => count($compat)];
                continue;
            }

            try {
                $warnings = $client->reviseCompatibility($offer->item_id, $offer->marketplace, $compat);
                foreach ($warnings as $w) {
                    $this->warn('   eBay: ' . mb_substr($w, 0, 200));
                }
                // Weryfikacja odczytem (ile wpisów FAKTYCZNIE siedzi na aukcji) — przy masowych
                // paczkach próbkowana (co 5. aukcja + każda z ostrzeżeniem „ungültig"), żeby nie
                // przepalać dziennego limitu Trading API na GetItem.
                $suspicious = (bool) collect($warnings)->first(fn ($w) => str_contains($w, 'ungültig') || stripos($w, 'invalid') !== false);
                $saved = null;
                if ($suspicious || count($report) % 5 === 0) {
                    $after = $client->itemCompatibility($offer->item_id, $offer->marketplace);
                    $saved = $after['count'];
                    $this->{$saved > 0 ? 'info' : 'error'}("   → na aukcji zapisane wpisy: {$saved} (wysłane: " . count($compat) . ')');
                } else {
                    $this->info('   ✓ wysłano ' . count($compat) . ' wpisów (bez weryfikacji odczytem)');
                }
                $status = $saved === 0 ? 'sent_but_empty' : 'sent';
                $report[] = ['item_id' => $offer->item_id, 'status' => $status, 'ebay_make' => $ebayMake, 'ebay_model' => $ebayModels, 'years' => $years, 'sent' => count($compat), 'saved' => $saved, 'warnings' => $warnings];
            } catch (\Throwable $e) {
                $this->error('   BŁĄD wysyłki: ' . $e->getMessage());
                $report[] = ['item_id' => $offer->item_id, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        $path = 'ebay/ktype-push-' . now()->format('Y-m-d-His') . ($apply ? '' : '-dryrun') . '.json';
        Storage::put($path, json_encode(array_merge($report, $mismatched), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        // Rejestr obrobionych: statusy terminalne nie wracają w kolejnych paczkach.
        // Błędy przejściowe (taxonomy_error, error wysyłki) NIE trafiają do rejestru — ponowią się.
        if ($apply) {
            $terminal = ['sent', 'sent_but_empty', 'unmatched', 'no_years', 'no_platform', 'title_mismatch', 'title_unparsed'];
            foreach (array_merge($report, $mismatched) as $r) {
                if (in_array($r['status'], $terminal)) {
                    $pushed[$r['item_id']] = $r['status'];
                }
            }
            Storage::put($pushedPath, json_encode($pushed, JSON_PRETTY_PRINT));
            $counts = array_count_values($pushed);
            $this->info('Rejestr łącznie: ' . collect($counts)->map(fn ($n, $s) => "{$s}={$n}")->implode(', '));
        }

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

        // Generacja: liczba po nazwie modelu w tytule aukcji lub nazwie produktu — „Grand Vitara 2”,
        // ale też oznaczenia serii „Land Cruiser J90” (J zdejmujemy; eBay ma model „Land Cruiser 90”).
        // 1–3 cyfry: nie łapie roczników (2004 = 4 cyfry).
        $generation = null;
        $modelWords = str_replace('-', ' ', $attrs['model']);
        foreach ([$offer->title, $offer->product->name['pl'] ?? '', $offer->product->name['en'] ?? ''] as $hint) {
            if ($hint && preg_match('/' . preg_quote($modelWords, '/') . '\s+j?(\d{1,3})\b/i', str_replace('-', ' ', $hint), $m)) {
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

    /**
     * Pojazd wyczytany z tytułu aukcji — dla bliźniaków badge'owych, gdzie produkt w PIM jest
     * pod marką konstruktora, a aukcja sprzedaje wersję innej marki.
     * Format tytułów: „Stahl Unterfahrschutz für Motor <MARKA> <MODEL> (RRRR-RRRR)".
     */
    private function vehicleFromTitle(EbayOffer $offer, array $fallback): ?array
    {
        // Zakres lat: zwykle „(2016-2026)", ale bywa i bez nawiasów. Gdy w tytule go nie ma
        // („…für Vers ohne Werksschutz") — roczniki bierzemy z atrybutów produktu.
        $hasYears = (bool) preg_match('/\(?\b(\d{4})\s*[-–]\s*(\d{4})\b\)?/u', $offer->title, $ym);
        $cut = $hasYears ? (int) mb_strpos($offer->title, $ym[0]) : mb_strlen($offer->title);
        $before = $this->norm(mb_substr($offer->title, 0, $cut));

        // Marka: ostatnie wystąpienie nazwy z bazy eBaya (nazwy części stoją PRZED marką);
        // przy tej samej pozycji wygrywa dłuższa („Land Rover" nad „Land"). Szukamy też pod
        // naszymi nazwami — w bazie jest „VW", a w tytułach „Volkswagen".
        $makes = $this->modelCache['__makes'] ??= $this->taxonomy->compatibilityPropertyValues($this->treeId, $this->categoryId, 'Make');
        $bestPos = -1;
        $bestLen = 0;
        $bestMake = null;
        foreach ($makes as $m) {
            $n = $this->norm($m);
            if ($n === '') {
                continue;
            }
            foreach (array_merge([$n], array_keys(self::MAKE_ALIASES, $n, true)) as $variant) {
                if (! preg_match('/\b' . preg_quote($variant, '/') . '\b/u', $before, $mm, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $pos = $mm[0][1];
                if ($pos > $bestPos || ($pos === $bestPos && strlen($variant) > $bestLen)) {
                    $bestPos = $pos;
                    $bestLen = strlen($variant);
                    $bestMake = $m;
                }
            }
        }

        // Tytuł bez marki („Golf 4 (1998-2006)") — marka z produktu, model dalej z tytułu.
        if (! $bestMake) {
            $bestMake = $fallback['make'];
            $bestPos = 0;
            $bestLen = 0;
        }

        $model = trim(substr($before, $bestPos + $bestLen));
        // Gdy model wyszedł z początku tytułu (brak marki) — zdejmij opis części.
        $model = trim(preg_replace('/^(stahl|aluminium|alu|unterfahrschutz|fur|und|,|\s)+/u', '', $model));
        if ($model === '') {
            return null;
        }

        // Generacja z oznaczenia serii w samym modelu („land cruiser j90" → 90).
        $generation = null;
        if (preg_match('/^(.*?)\s+j?(\d{1,3})$/u', $model, $g)) {
            $generation = (int) $g[2];
            $model = $g[1];
        }

        return [
            'make' => $bestMake,
            'model' => $model,
            'year_start' => $hasYears ? (int) $ym[1] : $fallback['year_start'],
            'year_stop' => $hasYears ? (int) $ym[2] : $fallback['year_stop'],
            'generation' => $generation,
        ];
    }

    /** Dopasuj pojazd do bazy eBaya: [Make, Model, lata]. Null gdy niejednoznaczne. */
    private function resolveVehicle(array $v): ?array
    {
        // Marka: wartości Make z bazy. Najpierw wprost (suzuki → Suzuki), potem po prefiksie —
        // eBay pisze pełne nazwy: mercedes → „Mercedes-Benz”, baic → „Baic-ORV”.
        $makes = $this->modelCache['__makes'] ??= $this->taxonomy->compatibilityPropertyValues($this->treeId, $this->categoryId, 'Make');
        $makeNorm = $this->norm($v['make']);
        $makeNorm = self::MAKE_ALIASES[$makeNorm] ?? $makeNorm;
        $ebayMake = collect($makes)->first(fn ($m) => $this->norm($m) === $makeNorm)
            ?: collect($makes)->first(fn ($m) => str_starts_with($this->norm($m), $makeNorm . ' '));
        if (! $ebayMake) {
            return null;
        }

        // Modele marki (cache per marka).
        $models = $this->modelCache[$ebayMake] ??= $this->taxonomy->compatibilityPropertyValues($this->treeId, $this->categoryId, 'Model', ['Make' => $ebayMake]);

        // Cel: baza modelu + generacja (rzymska w bazie eBaya: Grand Vitara II ↔ „grand vitara 2”).
        $base = $this->modelBase($v['model'], $makeNorm);
        $target = $v['generation'] ? "{$base} {$v['generation']}" : $base;

        $normalized = collect($models)->mapWithKeys(fn ($m) => [$m => $this->norm($m)]);
        // Dodatkowe porównanie bez spacji: nasze „SX 4" ↔ eBayowe „SX4".
        $squash = fn (string $s) => str_replace(' ', '', $s);
        $exact = $normalized->filter(fn ($n) => $n === $target || $squash($n) === $squash($target))->keys();

        if ($exact->isNotEmpty() && $v['generation']) {
            // Trafienie z generacją to mocny sygnał — nie rozszerzamy (Grand Vitara II ≠ Grand Vitara I).
            $candidates = $exact;
        } else {
            // Bez generacji: nazwa naga bywa pustym rekordem, a dane siedzą w wariantach nadwozia
            // („3008" ma 3 roczniki, „3008 SUV" pełne) — bierzemy oba i rozstrzygamy rocznikami.
            $candidates = $normalized
                ->filter(fn ($n) => $n === $base || $squash($n) === $squash($base) || str_starts_with($n, $base . ' '))
                ->keys()
                ->merge($exact)
                ->unique()
                ->values();
        }

        // Nadal nic? Nasza nazwa bywa szersza niż eBaya (hilux-invincible vs „Hilux VIII") —
        // skracaj bazę od prawej po jednym członie; wybór generacji rozstrzygną roczniki.
        $shrink = explode(' ', $base);
        while ($candidates->isEmpty() && count($shrink) > 1) {
            array_pop($shrink);
            $prefix = implode(' ', $shrink);
            $candidates = $normalized->filter(fn ($n) => $n === $prefix || str_starts_with($n, $prefix . ' '))->keys();
        }

        // Pokrycie roczników rozstrzyga, który wariant to nasz pojazd. Zbieramy WSZYSTKIE
        // o dobrym pokryciu — osłona pasuje do każdego nadwozia danej generacji (Vito Bus,
        // Kasten, Tourer…), więc ograniczanie się do jednego gubiłoby część kupujących.
        $yearsByModel = [];
        foreach ($candidates as $model) {
            $yearsByModel[$model] = $this->yearCache[$ebayMake . '|' . $model] ??= array_map('intval', $this->taxonomy->compatibilityPropertyValues($this->treeId, $this->categoryId, 'Year', ['Make' => $ebayMake, 'Model' => $model]));
        }

        // Nasz year_stop bywa „w produkcji" (2026), a baza eBaya sięga rocznika bieżącego —
        // liczenie pokrycia po surowym zakresie karałoby aktualne modele za brakującą przyszłość.
        $available = array_merge(...array_values($yearsByModel ?: [[]]));
        $rangeStop = $available ? min($v['year_stop'], max($available)) : $v['year_stop'];
        $span = max(1, $rangeStop - $v['year_start'] + 1);

        $scored = [];
        foreach ($yearsByModel as $model => $years) {
            $overlap = array_values(array_filter($years, fn ($y) => $y >= $v['year_start'] && $y <= $v['year_stop']));
            $cover = count($overlap) / $span;
            if ($cover > 0) {
                $scored[] = ['model' => $model, 'years' => $overlap, 'cover' => $cover, 'start_diff' => $years === [] ? PHP_INT_MAX : abs(min($years) - $v['year_start'])];
            }
        }

        usort($scored, fn ($a, $b) => [$b['cover'], $a['start_diff']] <=> [$a['cover'], $b['start_diff']]);
        $bestCover = $scored[0]['cover'] ?? 0;
        // Warianty muszą trzymać poziom najlepszego (0.8×) — inaczej wpadłaby sąsiednia generacja.
        $accepted = array_slice(array_filter($scored, fn ($s) => $s['cover'] >= 0.6 && $s['cover'] >= 0.8 * $bestCover), 0, 8);
        $best = $accepted ? [array_column($accepted, 'model'), array_values(array_unique(array_merge(...array_column($accepted, 'years'))))] : null;

        // Wymagamy sensownego pokrycia zakresu lat — inaczej to zgadywanie, nie dopasowanie.
        if (! $best || $bestCover < 0.6) {
            return null;
        }

        sort($best[1]);

        return [$ebayMake, $best[0], $best[1]];
    }

    /**
     * Nazwa modelu sprowadzona do porównania z bazą eBaya:
     * - zdejmuje prefiks marki („ssangyong tivoli” → „tivoli”),
     * - tłumaczy nasze konwencje na niemieckie („seria 1” → „1er”, „e classe” → „e klasse”,
     *   „ml” → „m klasse”).
     */
    private function modelBase(string $model, string $makeNorm): string
    {
        $base = $this->norm($model);

        if (str_starts_with($base, $makeNorm . ' ')) {
            $base = substr($base, strlen($makeNorm) + 1);
        }

        if (preg_match('/^seria (\d+)$/', $base, $m)) {
            return $m[1] . 'er';
        }
        if (preg_match('/^(.+) (classe|class)$/', $base, $m)) {
            return $m[1] . ' klasse';
        }
        // Mercedes: skróty nadwozia zamiast nazw klas (ML = M-Klasse, GL = GL-Klasse…).
        if ($makeNorm === 'mercedes' && preg_match('/^(ml|gl|slk|clk|cls)$/', $base, $m)) {
            return substr($m[1], 0, -1) . ' klasse';
        }

        return $base;
    }

    /** Normalizacja do porównań: bez diakrytyków, małe litery, myślniki→spacje, rzymskie→arabskie. */
    private function norm(string $s): string
    {
        // Citroën ↔ citroen, Škoda ↔ skoda — bez tego marki z diakrytykami nigdy nie trafiają.
        // Najpierw małe litery, potem podmiana (mapa pokrywa tylko małe znaki).
        $s = mb_strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $s))));
        $s = strtr($s, [
            'ë' => 'e', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ä' => 'a', 'à' => 'a', 'â' => 'a',
            'ö' => 'o', 'ô' => 'o', 'ü' => 'u', 'û' => 'u', 'ù' => 'u', 'ï' => 'i', 'î' => 'i',
            'ç' => 'c', 'ñ' => 'n', 'š' => 's', 'ž' => 'z', 'č' => 'c', 'ß' => 'ss',
        ]);
        $roman = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9, 'x' => 10, 'xi' => 11, 'xii' => 12];

        return implode(' ', array_map(
            fn ($w) => isset($roman[$w]) ? (string) $roman[$w] : $w,
            explode(' ', $s)
        ));
    }
}
