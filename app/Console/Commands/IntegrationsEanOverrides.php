<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\IntegrationProduct;
use Illuminate\Console\Command;

/**
 * Wypełnia kolumnę „EAN (nadpisanie)" w każdej integracji, czyli `integration_products.overrides['ean']`.
 *
 * Źródłem jest `products.ean`, które `sumpguard:ean-sync` ustawia wcześniej na kod dostawcy
 * (GS1 Rumunia, prefiks 594), a tam gdzie dostawca kodu nie nadał — zostawia nasz z puli 590.
 * Dzięki temu reguła „bierzemy ich, a gdzie nie ma nic dajemy nasze" realizuje się w jednym miejscu.
 *
 * `getOverridedProduct()` nakłada dowolny klucz overrides na model, a pipeline'y connectora z niego
 * korzystają — więc to nadpisanie realnie jedzie do sklepów, nie jest tylko ozdobą w gridzie.
 */
class IntegrationsEanOverrides extends Command
{
    protected $signature = 'integrations:ean-overrides
                            {--apply : Zapisz zmiany (bez tej flagi komenda tylko raportuje)}
                            {--integration= : Ogranicz do jednej integracji (id)}
                            {--keep-x : Zostaw wpisy o wartości "x" nietknięte}';

    protected $description = 'Przepisuje products.ean do pola „EAN (nadpisanie)" we wszystkich integracjach';

    public function handle(): int
    {
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
        $sumaX = 0;
        $wiersze = [];

        foreach ($integrations as $integration) {
            $doZapisu = [];
            $bezZmian = 0;
            $bezZrodla = 0;
            $iksy = 0;

            IntegrationProduct::with('product:id,ean,product_code')
                ->where('integration_id', $integration->id)
                ->chunkById(1000, function ($items) use (&$doZapisu, &$bezZmian, &$bezZrodla, &$iksy) {
                    foreach ($items as $ip) {
                        $zrodlo = trim((string)($ip->product->ean ?? ''));
                        if ($zrodlo === '') {
                            $bezZrodla++;   // produkt bez GTIN po żadnej stronie — nie ma co wpisać
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
                    }
                });

            $wiersze[] = [
                $integration->id,
                $integration->type,
                mb_substr((string)$integration->name, 0, 22),
                count($doZapisu),
                $bezZmian,
                $iksy,
                $bezZrodla,
            ];
            $sumaZmian += count($doZapisu);
            $sumaX += $iksy;

            if ($this->option('apply')) {
                foreach ($doZapisu as [$ip, $overrides]) {
                    // Zapis samego pola overrides; payload_hash zostaje, żeby delta-sync
                    // zobaczył różnicę i przepchnął produkt do sklepu.
                    $ip->updateQuietly(['overrides' => $overrides]);
                }
            }
        }

        $this->table(
            ['id', 'typ', 'nazwa', 'do zapisu', 'juz OK', '"x"', 'brak zrodla'],
            $wiersze
        );

        $this->newLine();
        $this->line("  Razem do zapisu: <fg=yellow>{$sumaZmian}</>");
        if ($sumaX > 0) {
            $this->line('  Wpisow "x": ' . $sumaX . ($this->option('keep-x') ? ' (zostawione)' : ' (zostana nadpisane realnym kodem)'));
        }

        if (!$this->option('apply')) {
            $this->newLine();
            $this->warn('DRY-RUN — nic nie zapisano. Dodaj --apply, zeby wykonac.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Zapisano {$sumaZmian} nadpisan EAN.");

        return self::SUCCESS;
    }
}
