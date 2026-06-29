<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\Pricelist;
use App\Models\PricelistProduct;
use Illuminate\Console\Command;
use Revolution\Google\Sheets\Facades\Sheets;

/**
 * Jednorazowy import Google Sheets -> baza, uruchamiany RAZ przy wdrozeniu migracji
 * z arkuszy na natywny grid (lokalnie i na produkcji). Lapie edycje zrobione w arkuszu,
 * a niezapisane przez PIM, zanim wytniemy zaleznosc od Google.
 *
 * Samodzielna (fasada Sheets + config), nie zalezy od GoogleSheetsService -> przetrwa
 * skasowanie serwisu. Idempotentna: tylko upsert, nigdy nie kasuje wierszy.
 */
class SheetsImportFinal extends Command
{
    protected $signature = 'sheets:import-final {--dry-run : Tylko policz, nie zapisuj}';

    protected $description = 'Jednorazowy import cennikow i nadpisan integracji z Google Sheets do bazy';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->importPricelists($dryRun);
        $this->importIntegrations($dryRun);

        $this->info('Gotowe.');

        return self::SUCCESS;
    }

    private function importPricelists(bool $dryRun): void
    {
        $spreadsheetId = config('sheets.pricelists.spreadsheet_id');

        Pricelist::whereNotNull('sheet_id')->each(function (Pricelist $pricelist) use ($spreadsheetId, $dryRun) {
            $rows = $this->getSpreadsheetData($spreadsheetId, $pricelist->sheet_id);

            $data = $rows
                ->filter(fn ($item) => !empty($item['id']))
                ->map(fn ($item) => [
                    'pricelist_id' => $pricelist->id,
                    'product_id' => $item['id'],
                    'price' => $this->normalizePrice($item['price'] ?? '') ?? 0,
                ])
                ->values()
                ->toArray();

            $this->line("Cennik #{$pricelist->id} ({$pricelist->name}): {$rows->count()} wierszy z arkusza, " . count($data) . ' do zapisu');

            if (!$dryRun && !empty($data)) {
                PricelistProduct::upsert($data, ['pricelist_id', 'product_id'], ['price']);
            }
        });
    }

    private function importIntegrations(bool $dryRun): void
    {
        $spreadsheetId = config('sheets.integrations.spreadsheet_id');

        Integration::whereNotNull('sheet_id')->each(function (Integration $integration) use ($spreadsheetId, $dryRun) {
            $rows = $this->getSpreadsheetData($spreadsheetId, $integration->sheet_id);

            $data = $rows
                ->filter(fn ($item) => !empty($item['id']))
                ->map(function ($item) use ($integration) {
                    $overrides = [];
                    foreach ($item as $key => $value) {
                        if (str_contains($key, 'overrides_') && (!empty($value) || $value == 0)) {
                            $overrides[str_replace('overrides_', '', $key)] = $value;
                        }
                    }

                    return [
                        'integration_id' => $integration->id,
                        'product_id' => $item['id'],
                        'overrides' => json_encode($overrides),
                    ];
                })
                ->values()
                ->toArray();

            $this->line("Integracja #{$integration->id} ({$integration->name}): {$rows->count()} wierszy z arkusza, " . count($data) . ' do zapisu');

            if (!$dryRun && !empty($data)) {
                IntegrationProduct::upsert($data, ['integration_id', 'product_id'], ['overrides']);
            }
        });
    }

    private function getSpreadsheetData(string $spreadsheetId, string $sheetId)
    {
        if (empty($spreadsheetId) || empty($sheetId)) {
            return collect();
        }

        $rows = Sheets::spreadsheet($spreadsheetId)->sheetById($sheetId)->all();

        if (empty($rows)) {
            return collect();
        }

        $header = array_shift($rows);

        if (empty($rows)) {
            return collect();
        }

        $maxColumns = max(array_map('count', $rows));

        if (count($header) < $maxColumns) {
            $header = array_pad($header, $maxColumns, null);
        } elseif (count($header) > $maxColumns) {
            $header = array_slice($header, 0, $maxColumns);
        }

        return Sheets::collection(header: $header, rows: $rows);
    }

    private function normalizePrice(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^\d.,-]/u', '', $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }

        return number_format((float) $value, 2, '.', '');
    }
}
