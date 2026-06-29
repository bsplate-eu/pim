<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\Product;
use App\Models\TranslationOverride;
use App\Models\TranslationPhrase;
use App\Models\TranslationPhraseRendition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importuje XLSX "Dane do matrycowania" do bazy:
 *   1. products.name      (PL/DE/CS/SK/FR/ES per slot)
 *   2. integration_products.overrides.name (per konto Allegro: 12/13/14/16/17/18)
 *   3. translation_phrases + renditions (matryca fraz, top-vote per kanał)
 *   4. translation_overrides (flaga 'sheet_import' = chroni przed Sumpguard sync)
 *
 * Idempotentne. Tryb --dry-run pokazuje plan bez zapisu.
 */
class TranslationsImportFromSheet extends Command
{
    protected $signature = 'translations:import-from-sheet
        {--file=storage/app/translations/source-sheet.xlsx : Ścieżka do XLSX}
        {--dry-run : Pokaż plan, nie zapisuj}
        {--no-matrix : Pomiń budowę matrycy fraz, importuj tylko per-produkt}
        {--limit=0 : Ogranicz do N pierwszych wierszy (0 = wszystkie)}';

    protected $description = 'Importuje tłumaczenia z arkusza XLSX do produktów, integracji Allegro i matrycy fraz';

    private const COL_ID = 1;
    private const COL_RAW = 3;

    /** kolumna XLSX → kanał w matrycy */
    private const CHANNELS = [
        4  => 'pl',
        5  => 'allegro_klapypodsilnik',
        6  => 'allegro_czescipareto',
        7  => 'allegro_dolneoslony',
        8  => 'allegro_ksteileshop',
        9  => 'allegro_oslonypareto',
        10 => 'de',
        11 => 'cs',
        12 => 'sk',
        13 => 'fr',
        14 => 'es',
    ];

    /** Kanał matrycy → locale w products.name (Spatie). Allegro nie ma — zapisywane jako overrides. */
    private const PRODUCT_LOCALE_CHANNELS = [
        'pl' => 'pl',
        'de' => 'de',
        'cs' => 'cs',
        'sk' => 'sk',
        'fr' => 'fr',
        'es' => 'es',
    ];

    /** Kanał allegro → integration_id w bazie. */
    private const ALLEGRO_INTEGRATION_MAP = [
        'allegro_klapypodsilnik' => 13,
        'allegro_czescipareto'   => 14,
        'allegro_dolneoslony'    => 16,
        'allegro_ksteileshop'    => 17,
        'allegro_oslonypareto'   => 18,
    ];

    /** Dodatkowe integracje którym wciskamy COPY z innego kanału. ID 12 = kopia 'allegro_oslonypareto'. */
    private const ALLEGRO_INTEGRATION_ALIAS = [
        12 => 'allegro_oslonypareto',
    ];

    private const BRAND_ALIASES = [
        'Volkswagen'    => ['VW', 'Vw'],
        'Mercedes-Benz' => ['Mercedes', 'MB'],
        'Mercedes'      => ['Mercedes-Benz', 'MB'],
        'Alfa Romeo'    => ['Alfa'],
        'Land Rover'    => ['Landrover'],
        'Mini Cooper'   => ['Mini'],
    ];

    private const PLACEHOLDERS = ['', 'x', 'X', '-', '--', 'n/a', 'none', '?'];

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        if (!file_exists($path)) {
            $this->error("Plik nie istnieje: {$path}");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — nic nie zostanie zapisane ===');
        }

        $this->info("Wczytuję {$path}...");
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $this->readRows($sheet, (int) $this->option('limit'));
        $this->info('Wierszy z id w arkuszu: ' . count($rows));

        $products = Product::with('attributeValues.attribute')
            ->whereIn('id', collect($rows)->pluck('id')->all())
            ->get()
            ->keyBy('id');
        $this->info('Produktów dopasowanych w PIM: ' . $products->count());

        $integrationSourceIds = $this->loadIntegrationSourceIds();

        $stats = [
            'products_updated'     => 0,
            'product_locales_set'  => 0,
            'overrides_marked'     => 0,
            'integration_products' => 0,
            'phrases_created'      => 0,
            'renditions_created'   => 0,
            'skipped_no_product'   => 0,
            'skipped_placeholder'  => 0,
        ];

        if ($dryRun) {
            $stats = $this->processRows($rows, $products, $integrationSourceIds, $stats, true);
            $this->printSummary($stats);
            return self::SUCCESS;
        }

        TranslationOverride::$suppressObserver = true;
        try {
            DB::transaction(function () use ($rows, $products, $integrationSourceIds, &$stats) {
                $stats = $this->processRows($rows, $products, $integrationSourceIds, $stats, false);

                if (!$this->option('no-matrix')) {
                    $matrixStats = $this->persistMatrix($rows, $products);
                    $stats = array_merge($stats, $matrixStats);
                }
            });
        } finally {
            TranslationOverride::$suppressObserver = false;
        }

        $this->printSummary($stats);
        return self::SUCCESS;
    }

    private function readRows($sheet, int $limit): array
    {
        $maxRow = $sheet->getHighestRow();
        $rows = [];
        for ($r = 2; $r <= $maxRow; $r++) {
            $id = $sheet->getCell([self::COL_ID, $r])->getValue();
            if (!is_numeric($id)) continue;
            $row = ['id' => (int) $id, 'raw' => trim((string) $sheet->getCell([self::COL_RAW, $r])->getValue())];
            foreach (self::CHANNELS as $col => $channel) {
                $row[$channel] = trim((string) $sheet->getCell([$col, $r])->getValue());
            }
            $rows[] = $row;
            if ($limit > 0 && count($rows) >= $limit) break;
        }
        return $rows;
    }

    private function loadIntegrationSourceIds(): array
    {
        $allIds = array_merge(array_values(self::ALLEGRO_INTEGRATION_MAP), array_keys(self::ALLEGRO_INTEGRATION_ALIAS));
        $map = [];
        foreach (Integration::whereIn('id', $allIds)->with('integrationSources')->get() as $i) {
            $first = $i->integrationSources->first();
            $map[$i->id] = $first?->id;
        }
        return $map;
    }

    private function processRows(array $rows, $products, array $integrationSourceIds, array $stats, bool $dryRun): array
    {
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach ($rows as $row) {
            $product = $products->get($row['id']);
            if (!$product) {
                $stats['skipped_no_product']++;
                $bar->advance();
                continue;
            }

            // === 1. Produkt: setTranslation per locale ===
            $localesSet = 0;
            foreach (self::PRODUCT_LOCALE_CHANNELS as $channel => $locale) {
                $value = $row[$channel] ?? '';
                if ($this->isPlaceholder($value)) continue;

                if (!$dryRun) {
                    $product->setTranslation('name', $locale, $value);
                }
                $localesSet++;
            }

            if ($localesSet > 0 && !$dryRun) {
                $product->save();
                foreach (self::PRODUCT_LOCALE_CHANNELS as $channel => $locale) {
                    $value = $row[$channel] ?? '';
                    if ($this->isPlaceholder($value)) continue;
                    TranslationOverride::mark($product, 'name', $locale, TranslationOverride::SOURCE_SHEET_IMPORT);
                    $stats['overrides_marked']++;
                }
            }
            if ($localesSet > 0) {
                $stats['products_updated']++;
                $stats['product_locales_set'] += $localesSet;
            }

            // === 2. IntegrationProduct.overrides.name per konto Allegro ===
            foreach (self::ALLEGRO_INTEGRATION_MAP as $channel => $integrationId) {
                $value = $row[$channel] ?? '';
                if ($this->isPlaceholder($value)) continue;
                if (!$dryRun) {
                    $this->upsertIntegrationOverride($integrationId, $product->id, $value, $integrationSourceIds[$integrationId] ?? null);
                }
                $stats['integration_products']++;
            }

            // Aliasy: ID 12 dostaje wartość z kanału ALIAS
            foreach (self::ALLEGRO_INTEGRATION_ALIAS as $integrationId => $sourceChannel) {
                $value = $row[$sourceChannel] ?? '';
                if ($this->isPlaceholder($value)) continue;
                if (!$dryRun) {
                    $this->upsertIntegrationOverride($integrationId, $product->id, $value, $integrationSourceIds[$integrationId] ?? null);
                }
                $stats['integration_products']++;
            }

            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        return $stats;
    }

    /**
     * Insert lub update overrides.name dla pary (integration_id, product_id).
     * Zachowuje istniejące pola w overrides (np. 'ean').
     */
    private function upsertIntegrationOverride(int $integrationId, int $productId, string $name, ?int $integrationSourceId): void
    {
        $ip = IntegrationProduct::firstOrNew([
            'integration_id' => $integrationId,
            'product_id'     => $productId,
        ]);
        if (!$ip->exists && $integrationSourceId) {
            $ip->integration_source_id = $integrationSourceId;
            $ip->state = IntegrationProduct::STATE_PENDING;
        }
        $overrides = $ip->overrides ?? [];
        $overrides['name'] = $name;
        $ip->overrides = $overrides;
        $ip->save();

        TranslationOverride::mark($ip, 'overrides.name', 'default', TranslationOverride::SOURCE_SHEET_IMPORT);
    }

    private function persistMatrix(array $rows, $products): array
    {
        $stats = ['phrases_created' => 0, 'renditions_created' => 0];

        // Agreguj wiersze: pl_prefix => [channel => [value => count]]
        $byPlPrefix = [];
        foreach ($rows as $row) {
            $product = $products->get($row['id']);
            if (!$product) continue;
            [$make, $model] = $this->getMakeModel($product);

            $prefixes = [];
            foreach (self::CHANNELS as $col => $channel) {
                $val = $row[$channel] ?? '';
                if ($this->isPlaceholder($val)) {
                    $prefixes[$channel] = null;
                    continue;
                }
                $stripped = $this->stripSuffix($val, $make, $model);
                $prefixes[$channel] = $stripped !== '' ? $stripped : null;
            }
            $plPrefix = $prefixes['pl'];
            if (!$plPrefix) continue;

            $key = mb_strtolower($plPrefix);
            if (!isset($byPlPrefix[$key])) {
                $byPlPrefix[$key] = ['phrase' => $plPrefix, 'count' => 0, 'channels' => []];
            }
            $byPlPrefix[$key]['count']++;
            foreach (self::CHANNELS as $col => $channel) {
                $v = $prefixes[$channel];
                if (!$v) continue;
                $byPlPrefix[$key]['channels'][$channel][$v] = ($byPlPrefix[$key]['channels'][$channel][$v] ?? 0) + 1;
            }
        }

        // Zapisz: per pl_prefix → phrase + winners per channel
        foreach ($byPlPrefix as $data) {
            $slug = Str::slug($data['phrase'], '_');
            if (mb_strlen($slug) > 200) $slug = mb_substr($slug, 0, 200);
            $phrase = TranslationPhrase::updateOrCreate(
                ['slug' => $slug],
                ['phrase_pl' => $data['phrase'], 'product_count' => $data['count']]
            );
            if ($phrase->wasRecentlyCreated) $stats['phrases_created']++;

            foreach ($data['channels'] as $channel => $values) {
                if (empty($values)) continue;
                arsort($values);
                $topValue = (string) array_key_first($values);
                $variants = count($values);
                $rendition = TranslationPhraseRendition::updateOrCreate(
                    ['translation_phrase_id' => $phrase->id, 'channel' => $channel],
                    ['value' => $topValue, 'source' => 'sheet_import', 'variants_count' => $variants]
                );
                if ($rendition->wasRecentlyCreated) $stats['renditions_created']++;
            }
        }

        return $stats;
    }

    private function isPlaceholder(string $v): bool
    {
        return in_array(trim($v), self::PLACEHOLDERS, true);
    }

    private function getMakeModel(Product $product): array
    {
        $make = $model = null;
        foreach ($product->attributeValues as $av) {
            $slug = $av->attribute?->slug;
            if ($slug === 'make' && !$make) $make = $av->getTranslation('name', 'pl');
            elseif ($slug === 'model' && !$model) $model = $av->getTranslation('name', 'pl');
        }
        return [$make, $model];
    }

    private function stripSuffix(string $text, ?string $make, ?string $model): string
    {
        $text = trim($text);
        if ($text === '') return '';

        $parts = [$model, $make];
        if ($make && isset(self::BRAND_ALIASES[$make])) {
            $parts = array_merge($parts, self::BRAND_ALIASES[$make]);
        }

        for ($pass = 0; $pass < 3; $pass++) {
            $changed = false;
            foreach ($parts as $part) {
                if (!$part) continue;
                $part = trim($part);
                if ($part === '') continue;
                $pattern = '/[\s\-_,()]*' . preg_quote($part, '/') . '\s*$/iu';
                $new = preg_replace($pattern, '', $text);
                if ($new !== $text) {
                    $text = trim($new);
                    $changed = true;
                }
            }
            if (!$changed) break;
        }
        return $text;
    }

    private function printSummary(array $stats): void
    {
        $this->newLine();
        $this->line('=== PODSUMOWANIE ===');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-25s %d', $k, $v));
        }
    }
}
