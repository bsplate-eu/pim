<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class ConnectImportPostalCodesCommand extends Command
{
    protected $signature = 'connect:import-postal-codes
        {--countries= : Lista krajów oddzielonych przecinkami (ISO2), np. PL,DE,CZ}
        {--all-eu : Domyślny zestaw 28 krajów europejskich}
        {--fresh : Najpierw usuń istniejące wpisy importowanych krajów}';

    protected $description = 'Importuje kody pocztowe z GeoNames (https://download.geonames.org/export/zip/) do geo_postal_codes.';

    /**
     * Domyślny zestaw krajów europejskich — pokrywa typowe kierunki dostaw BL.
     */
    private const DEFAULT_EU = [
        'PL', 'DE', 'CZ', 'SK', 'AT', 'FR', 'IT', 'GB', 'NL', 'BE',
        'SE', 'NO', 'DK', 'ES', 'PT', 'HU', 'RO', 'BG', 'HR', 'SI',
        'EE', 'LT', 'LV', 'FI', 'IE', 'GR', 'CH', 'UA',
    ];

    private const BASE_URL = 'https://download.geonames.org/export/zip/';

    private const BATCH_SIZE = 1000;

    public function handle(): int
    {
        $countries = $this->resolveCountries();
        if ($countries === null) {
            return self::FAILURE;
        }

        $tmpDir = storage_path('app/geonames-tmp');
        File::ensureDirectoryExists($tmpDir);

        $totalInserted = 0;

        foreach ($countries as $cc) {
            $this->newLine();
            $this->info("=== {$cc} ===");

            if ($this->option('fresh')) {
                $deleted = DB::table('geo_postal_codes')->where('country_code', $cc)->delete();
                $this->line("  Usunięto {$deleted} istniejących wpisów.");
            }

            $inserted = $this->importCountry($cc, $tmpDir);
            if ($inserted !== null) {
                $this->info("  Wstawiono {$inserted} rekordów dla {$cc}.");
                $totalInserted += $inserted;
            }
        }

        $this->newLine();
        $this->info("Łącznie wstawiono: {$totalInserted} rekordów.");

        return self::SUCCESS;
    }

    private function resolveCountries(): ?array
    {
        if ($this->option('all-eu')) {
            return self::DEFAULT_EU;
        }

        $cs = $this->option('countries');
        if (! $cs) {
            $this->error('Podaj --countries=PL,DE,... lub --all-eu.');

            return null;
        }

        return array_values(array_filter(array_map(
            fn ($c) => strtoupper(trim($c)),
            explode(',', $cs)
        )));
    }

    private function importCountry(string $cc, string $tmpDir): ?int
    {
        $zipPath = "{$tmpDir}/{$cc}.zip";
        $extractDir = "{$tmpDir}/{$cc}";
        $txtPath = "{$extractDir}/{$cc}.txt";

        if (! $this->downloadZip($cc, $zipPath)) {
            return null;
        }

        if (! $this->extractZip($zipPath, $extractDir)) {
            return null;
        }

        if (! File::exists($txtPath)) {
            $this->error("  Brak pliku {$cc}.txt w archiwum.");

            return null;
        }

        $inserted = $this->parseAndInsert($txtPath);

        @unlink($zipPath);
        File::deleteDirectory($extractDir);

        return $inserted;
    }

    private function downloadZip(string $cc, string $zipPath): bool
    {
        $this->line('  Pobieram ZIP…');
        try {
            $response = Http::timeout(120)->get(self::BASE_URL . "{$cc}.zip");
            if (! $response->successful()) {
                $this->error("  Błąd pobierania: HTTP {$response->status()}");

                return false;
            }
            file_put_contents($zipPath, $response->body());

            return true;
        } catch (\Throwable $e) {
            $this->error("  Błąd pobierania: {$e->getMessage()}");

            return false;
        }
    }

    private function extractZip(string $zipPath, string $extractDir): bool
    {
        $this->line('  Rozpakowuję…');
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->error('  Nie można otworzyć ZIP-a.');

            return false;
        }
        $zip->extractTo($extractDir);
        $zip->close();

        return true;
    }

    private function parseAndInsert(string $txtPath): int
    {
        $this->line('  Wstawiam do bazy…');
        $handle = fopen($txtPath, 'r');
        if ($handle === false) {
            return 0;
        }

        $batch = [];
        $inserted = 0;

        while (($line = fgets($handle)) !== false) {
            $parts = explode("\t", rtrim($line, "\r\n"));
            if (count($parts) < 11) {
                continue;
            }

            $cc = substr($parts[0], 0, 2);
            $postcode = substr($parts[1], 0, 30);
            $lat = $parts[9];
            $lng = $parts[10];

            if ($cc === '' || $postcode === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $batch[] = [
                'country_code' => $cc,
                'postal_code'  => $postcode,
                'place_name'   => $this->nullableString($parts[2] ?? null, 180),
                'admin_name1'  => $this->nullableString($parts[3] ?? null, 100),
                'latitude'     => (float) $lat,
                'longitude'    => (float) $lng,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $inserted += $this->bulkInsert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            $inserted += $this->bulkInsert($batch);
        }
        fclose($handle);

        return $inserted;
    }

    private function nullableString(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    private function bulkInsert(array $rows): int
    {
        return DB::table('geo_postal_codes')->insertOrIgnore($rows);
    }
}
