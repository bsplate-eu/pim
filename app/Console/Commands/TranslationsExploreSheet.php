<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Analizuje XLSX z ręcznymi tłumaczeniami i proponuje matrycę fraz.
 * NIE dotyka bazy — output to JSON + raport na konsoli.
 *
 * Wejście: kolumny arkusza
 *   1 id | 2 external_id | 3 nazwa z Sumpguard | 4 PL | 5-9 5 kont Allegro | 10 DE | 11 CS | 12 SK | 13 FR | 14 ES
 *
 * Algorytm:
 *   Dla każdego wiersza z `id` ładuje produkt z PIM, czyta atrybuty make+model,
 *   odcina je z końca każdej z 11 wartości tłumaczeniowych. Reszta = "prefix" (typ produktu).
 *   Grupuje wiersze po PL-prefix, zlicza spójność tłumaczeń DE/CZ/SK/FR/ES + 5 Allegro.
 */
class TranslationsExploreSheet extends Command
{
    protected $signature = 'translations:explore-sheet
        {--file=storage/app/translations/source-sheet.xlsx : Ścieżka do XLSX (względem base_path)}
        {--limit=0 : Ogranicz do N pierwszych wierszy (0 = wszystkie)}
        {--out=storage/app/translations/proposed-matrix.json : Plik wyjściowy}';

    protected $description = 'Eksploruje arkusz z tłumaczeniami i proponuje matrycę fraz (bez zmian w bazie)';

    private const COL_ID         = 1;
    private const COL_EXTERNAL   = 2;
    private const COL_RAW        = 3;
    private const COL_PL         = 4;
    private const COL_DE         = 10;
    private const COL_CS         = 11;
    private const COL_SK         = 12;
    private const COL_FR         = 13;
    private const COL_ES         = 14;

    /** kolumna XLSX => kanał w matrycy */
    private const CHANNELS = [
        self::COL_PL => 'pl',
        5            => 'allegro_klapypodsilnik',
        6            => 'allegro_czescipareto',
        7            => 'allegro_dolneoslony',
        8            => 'allegro_ksteileshop',
        9            => 'allegro_oslonypareto',
        self::COL_DE => 'de',
        self::COL_CS => 'cs',
        self::COL_SK => 'sk',
        self::COL_FR => 'fr',
        self::COL_ES => 'es',
    ];

    /** Aliasy marek występujące w PL nazwach a różne od atrybutu `make`. */
    private const BRAND_ALIASES = [
        'Volkswagen'    => ['VW', 'Vw'],
        'Mercedes-Benz' => ['Mercedes', 'MB'],
        'Mercedes'      => ['Mercedes-Benz', 'MB'],
        'Alfa Romeo'    => ['Alfa'],
        'Land Rover'    => ['Landrover'],
        'Mini Cooper'   => ['Mini'],
    ];

    /** Wartości traktowane jako brak tłumaczenia (placeholdery z arkusza). */
    private const PLACEHOLDERS = ['', 'x', 'X', '-', '--', 'n/a', 'none', '?'];

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        if (!file_exists($path)) {
            $this->error("Plik nie istnieje: {$path}");
            return self::FAILURE;
        }

        $this->info("Wczytuję {$path}...");
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $maxRow = $sheet->getHighestRow();
        $limit = (int) $this->option('limit');

        $rows = $this->readRows($sheet, $maxRow, $limit);
        $this->info('Wierszy z id w arkuszu: ' . count($rows));

        $products = Product::with('attributeValues.attribute')
            ->whereIn('id', collect($rows)->pluck('id')->all())
            ->get()
            ->keyBy('id');

        $this->info('Produktów dopasowanych w PIM: ' . $products->count());

        $analysis = $this->analyze($rows, $products);
        $this->printReport($analysis);

        $outPath = base_path($this->option('out'));
        @mkdir(dirname($outPath), 0775, true);
        file_put_contents($outPath, json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Wynik zapisany: {$outPath}");

        return self::SUCCESS;
    }

    private function readRows($sheet, int $maxRow, int $limit): array
    {
        $rows = [];
        for ($r = 2; $r <= $maxRow; $r++) {
            $id = $sheet->getCell([self::COL_ID, $r])->getValue();
            if (!is_numeric($id)) {
                continue;
            }
            $row = ['id' => (int) $id, 'external_id' => $sheet->getCell([self::COL_EXTERNAL, $r])->getValue()];
            $row['raw'] = trim((string) $sheet->getCell([self::COL_RAW, $r])->getValue());
            foreach (self::CHANNELS as $col => $channel) {
                $row[$channel] = trim((string) $sheet->getCell([$col, $r])->getValue());
            }
            $rows[] = $row;
            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }

    private function isPlaceholder(string $v): bool
    {
        return in_array(trim($v), self::PLACEHOLDERS, true);
    }

    /**
     * Strip make+model+aliasy marki z końca tekstu (case-insensitive).
     * "Stalowa osłona silnika VW Golf" + make=Volkswagen, model=Golf → "Stalowa osłona silnika"
     */
    private function stripSuffix(string $text, ?string $make, ?string $model): string
    {
        $text = trim($text);
        if ($text === '') return '';

        $parts = [$model, $make];
        if ($make && isset(self::BRAND_ALIASES[$make])) {
            $parts = array_merge($parts, self::BRAND_ALIASES[$make]);
        }

        // Wielokrotny pass — bo "VW Golf" wymaga strippować Golf, potem VW (alias Volkswagen)
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

    private function analyze(array $rows, $products): array
    {
        $channels = array_values(self::CHANNELS);
        $byPlPrefix = []; // pl_prefix => ['count' => N, 'channels' => [ch => [val => count]], 'product_ids' => [...], 'examples' => [...]]
        $noProduct = [];
        $missingMakeModel = [];
        $skippedPlaceholder = 0;

        foreach ($rows as $row) {
            $product = $products->get($row['id']);
            if (!$product) {
                $noProduct[] = $row['id'];
                continue;
            }

            [$make, $model] = $this->getMakeModel($product);
            if (!$make && !$model) {
                $missingMakeModel[] = $row['id'];
            }

            // Wyciągnij prefixy per kanał (placeholdery → null)
            $prefixes = [];
            foreach ($channels as $ch) {
                $val = $row[$ch] ?? '';
                if ($this->isPlaceholder($val)) {
                    $prefixes[$ch] = null;
                    continue;
                }
                $prefixes[$ch] = $this->stripSuffix($val, $make, $model);
                if ($prefixes[$ch] === '') $prefixes[$ch] = null;
            }

            $plPrefix = $prefixes['pl'] ?? null;
            if (!$plPrefix) {
                $skippedPlaceholder++;
                continue;
            }

            $key = mb_strtolower($plPrefix);
            if (!isset($byPlPrefix[$key])) {
                $byPlPrefix[$key] = [
                    'pl_prefix' => $plPrefix,
                    'count' => 0,
                    'channels' => [],
                    'product_ids' => [],
                ];
                foreach ($channels as $ch) {
                    $byPlPrefix[$key]['channels'][$ch] = [];
                }
            }
            $byPlPrefix[$key]['count']++;
            if (count($byPlPrefix[$key]['product_ids']) < 5) {
                $byPlPrefix[$key]['product_ids'][] = $row['id'];
            }
            foreach ($channels as $ch) {
                $v = $prefixes[$ch];
                if ($v === null || $v === '') continue;
                $byPlPrefix[$key]['channels'][$ch][$v] = ($byPlPrefix[$key]['channels'][$ch][$v] ?? 0) + 1;
            }
        }

        // Dla każdego pl_prefix wybierz "dominującą" wartość per kanał + flaguj niespójności
        $matrix = [];
        $inconsistencies = [];
        foreach ($byPlPrefix as $key => $data) {
            $entry = [
                'slug' => Str::slug($data['pl_prefix'], '_'),
                'pl_prefix' => $data['pl_prefix'],
                'product_count' => $data['count'],
                'sample_product_ids' => $data['product_ids'],
                'renditions' => [],
            ];
            foreach ($data['channels'] as $ch => $values) {
                if (empty($values)) {
                    $entry['renditions'][$ch] = ['value' => null, 'coverage' => 0, 'variants' => 0];
                    continue;
                }
                arsort($values);
                $topValue = array_key_first($values);
                $topCount = $values[$topValue];
                $coverage = $data['count'] > 0 ? round(100 * $topCount / $data['count'], 1) : 0;
                $entry['renditions'][$ch] = [
                    'value' => $topValue,
                    'coverage' => $coverage,
                    'variants' => count($values),
                ];
                if (count($values) > 1) {
                    $inconsistencies[] = [
                        'pl_prefix' => $data['pl_prefix'],
                        'channel' => $ch,
                        'variants' => $values,
                    ];
                }
            }
            $matrix[] = $entry;
        }

        // Sort by product_count DESC
        usort($matrix, fn ($a, $b) => $b['product_count'] <=> $a['product_count']);

        return [
            'meta' => [
                'rows_total' => count($rows),
                'products_matched' => count($rows) - count($noProduct),
                'skipped_placeholder_pl' => $skippedPlaceholder,
                'unique_pl_prefixes' => count($matrix),
                'channels' => $channels,
                'inconsistencies_count' => count($inconsistencies),
            ],
            'matrix' => $matrix,
            'inconsistencies' => $inconsistencies,
            'no_product_ids' => $noProduct,
            'missing_make_model_ids' => $missingMakeModel,
        ];
    }

    private function getMakeModel(Product $product): array
    {
        $make = null;
        $model = null;
        foreach ($product->attributeValues as $av) {
            $slug = $av->attribute?->slug;
            if ($slug === 'make' && !$make) {
                $make = $av->getTranslation('name', 'pl');
            } elseif ($slug === 'model' && !$model) {
                $model = $av->getTranslation('name', 'pl');
            }
        }
        return [$make, $model];
    }

    private function printReport(array $a): void
    {
        $this->newLine();
        $this->line('=== PODSUMOWANIE ===');
        $this->line("Wierszy w arkuszu: {$a['meta']['rows_total']}");
        $this->line("Dopasowanych produktów: {$a['meta']['products_matched']}");
        $this->line("Pomijane (placeholder \"x\" w PL): {$a['meta']['skipped_placeholder_pl']}");
        $this->line("Unikalnych PL-prefiksów (= proponowanych fraz w matrycy): {$a['meta']['unique_pl_prefixes']}");
        $this->line("Niespójności (różne wartości dla tej samej PL-frazy): {$a['meta']['inconsistencies_count']}");
        $this->line('Brak produktu w PIM dla id: ' . count($a['no_product_ids']));
        $this->line('Brak make/model w atrybutach: ' . count($a['missing_make_model_ids']));

        $this->newLine();
        $this->line('=== TOP-15 fraz wg liczby produktów ===');
        $head = ['#', 'count', 'pl_prefix', 'de', 'cs', 'fr'];
        $rows = [];
        foreach (array_slice($a['matrix'], 0, 15) as $i => $entry) {
            $rows[] = [
                $i + 1,
                $entry['product_count'],
                $this->shorten($entry['pl_prefix'], 38),
                $this->shorten($entry['renditions']['de']['value'] ?? '-', 40),
                $this->shorten($entry['renditions']['cs']['value'] ?? '-', 25),
                $this->shorten($entry['renditions']['fr']['value'] ?? '-', 45),
            ];
        }
        $this->table($head, $rows);

        if ($a['meta']['inconsistencies_count'] > 0) {
            $this->newLine();
            $this->line('=== NIESPÓJNOŚCI (top 10) — różne tłumaczenia dla tej samej PL-frazy ===');
            foreach (array_slice($a['inconsistencies'], 0, 10) as $inc) {
                $variants = collect($inc['variants'])->map(fn ($n, $v) => "  [{$n}x] {$v}")->implode("\n");
                $this->line("• PL: \"{$inc['pl_prefix']}\" — kanał {$inc['channel']}:");
                $this->line($variants);
            }
        }
    }

    private function shorten(?string $s, int $max): string
    {
        if ($s === null) return '-';
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
