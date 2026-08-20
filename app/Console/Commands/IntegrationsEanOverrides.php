<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\IntegrationProduct;
use Illuminate\Console\Command;

/**
 * Wypełnia kolumnę „EAN (nadpisanie)" w każdej integracji, czyli `integration_products.overrides['ean']`.
 *
 * Podział ustalony 2026-08-20:
 *   - kolumna główna „EAN" (`products.ean`) trzyma NASZE kody z puli GS1 Polska (prefiks 5906118),
 *   - kolumna „EAN (nadpisanie)" dostaje kod DOSTAWCY z feedu (GS1 Rumunia, prefiks 594),
 *   - a tam, gdzie dostawca kodu nie nadał, do nadpisania trafia nasz kod z kolumny głównej.
 *
 * `getOverridedProduct()` nakłada dowolny klucz overrides na model, a pipeline'y connectora z niego
 * korzystają — więc to nadpisanie realnie jedzie do sklepów, nie jest tylko ozdobą w gridzie.
 */
class IntegrationsEanOverrides extends Command
{
    protected $signature = 'integrations:ean-overrides
                            {--apply : Zapisz zmiany (bez tej flagi komenda tylko raportuje)}
                            {--integration= : Ogranicz do jednej integracji (id)}
                            {--locale=pl : Który plik feedu czytać (EAN-y są identyczne we wszystkich)}
                            {--keep-x : Zostaw wpisy o wartości "x" nietknięte}';

    protected $description = 'Wpisuje EAN dostawcy do pola „EAN (nadpisanie)" we wszystkich integracjach';

    public function handle(): int
    {
        $locale = (string)$this->option('locale');
        $path = storage_path("app/sumpguard/{$locale}.json");

        if (!is_file($path)) {
            $this->error("Brak pliku feedu: {$path}");
            return self::FAILURE;
        }

        $feed = json_decode((string)file_get_contents($path), true);
        if (!is_array($feed)) {
            $this->error("Feed nie jest poprawnym JSON-em: {$path}");
            return self::FAILURE;
        }

        $feedEans = [];
        foreach ($feed as $item) {
            $ean = $this->normalizeEan((string)($item['ean'] ?? ''));
            if ($ean !== null) {
                $feedEans[(string)($item['id'] ?? '')] = $ean;
            }
        }

        $this->info(sprintf(
            'Feed: %s, %d pozycji, %d z poprawnym EAN, plik z %s',
            basename($path), count($feed), count($feedEans), date('Y-m-d H:i', filemtime($path))
        ));

        $query = Integration::query()->orderBy('id');
        if ($id = $this->option('integration')) {
            $query->where('id', (int)$id);
        }
        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->error('Nie znaleziono integracji.');
            return self::FAILURE;
        }

        $sumaZmian = 0;
        $sumaNasze = 0;
        $wiersze = [];

        foreach ($integrations as $integration) {
            $doZapisu = [];
            $bezZmian = 0;
            $nasze = 0;
            $bezZrodla = 0;
            $iksy = 0;

            IntegrationProduct::with('product:id,ean,external_id,product_code')
                ->where('integration_id', $integration->id)
                ->chunkById(1000, function ($items) use ($feedEans, &$doZapisu, &$bezZmian, &$nasze, &$bezZrodla, &$iksy) {
                    foreach ($items as $ip) {
                        if (!$ip->product) {
                            continue;
                        }

                        // Kod dostawcy ma pierwszeństwo; gdy go nie ma — nasz z kolumny głównej.
                        $zrodlo = $feedEans[(string)$ip->product->external_id] ?? null;
                        $zNaszych = false;
                        if ($zrodlo === null) {
                            $zrodlo = trim((string)($ip->product->ean ?? ''));
                            $zNaszych = $zrodlo !== '';
                        }

                        if ($zrodlo === '' || $zrodlo === null) {
                            $bezZrodla++;   // ani dostawca, ani my nie mamy kodu
                            continue;
                        }

                        $overrides = $ip->overrides ?? [];
                        $obecne = trim((string)($overrides['ean'] ?? ''));

                        if ($obecne === 'x') {
                            $iksy++;
                            if ($this->option('keep-x')) {
                                continue;
                            }
                        }

                        if ($obecne === $zrodlo) {
                            $bezZmian++;
                            continue;
                        }

                        $overrides['ean'] = $zrodlo;
                        $doZapisu[] = [$ip, $overrides];
                        if ($zNaszych) {
                            $nasze++;
                        }
                    }
                });

            $wiersze[] = [
                $integration->id,
                $integration->type,
                mb_substr((string)$integration->name, 0, 22),
                count($doZapisu),
                $nasze,
                $bezZmian,
                $iksy,
                $bezZrodla,
            ];
            $sumaZmian += count($doZapisu);
            $sumaNasze += $nasze;

            if ($this->option('apply')) {
                foreach ($doZapisu as [$ip, $overrides]) {
                    // Zapis samego pola overrides; payload_hash zostaje, żeby delta-sync
                    // zobaczył różnicę i przepchnął produkt do sklepu.
                    $ip->updateQuietly(['overrides' => $overrides]);
                }
            }
        }

        $this->table(
            ['id', 'typ', 'nazwa', 'do zapisu', 'w tym nasze', 'juz OK', '"x"', 'brak zrodla'],
            $wiersze
        );

        $this->newLine();
        $this->line("  Razem do zapisu: <fg=yellow>{$sumaZmian}</>  (w tym naszych kodow 590: {$sumaNasze})");

        if (!$this->option('apply')) {
            $this->newLine();
            $this->warn('DRY-RUN — nic nie zapisano. Dodaj --apply, zeby wykonac.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Zapisano {$sumaZmian} nadpisan EAN.");

        return self::SUCCESS;
    }

    private function normalizeEan(string $raw): ?string
    {
        $ean = preg_replace('/\D/', '', $raw);
        if ($ean === '' || strlen($ean) !== 13) {
            return null;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int)$ean[$i]) * ($i % 2 ? 3 : 1);
        }

        return ((10 - $sum % 10) % 10) === (int)$ean[12] ? $ean : null;
    }
}
