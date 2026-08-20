<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Przepisuje EAN-y z feedu dostawcy (sump-guard, GS1 Rumunia / prefiks 594) do `products.ean`.
 *
 * Osobna komenda, a nie kawałek `sources:sync`, z dwóch powodów:
 *  - `sources:sync` rusza nazwy, zdjęcia, kategorie i cennik naraz — po incydencie z 11.08.2026
 *    nie chcemy odpalać go tylko po to, żeby przestawić jedno pole;
 *  - zmiana GTIN-u na żywych ofertach jest wrażliwa, więc musi mieć własny dry-run i własny log.
 *
 * Reguła (ustalona 2026-08-20): kod dostawcy jest nadrzędny i nadpisuje nasz. Tam, gdzie dostawca
 * kodu nie nadał, zostaje nasz własny z puli 590 — pola po prostu nie dotykamy.
 */
class SumpguardEanSync extends Command
{
    protected $signature = 'sumpguard:ean-sync
                            {--apply : Zapisz zmiany (bez tej flagi komenda tylko raportuje)}
                            {--locale=pl : Który plik feedu czytać (EAN-y są identyczne we wszystkich)}
                            {--limit=25 : Ile przykładowych zmian wypisać}';

    protected $description = 'Wgrywa EAN-y dostawcy do products.ean (dry-run domyślnie)';

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

        $this->info(sprintf(
            'Feed: %s (%s), %d pozycji, plik z %s',
            basename($path), $locale, count($feed), date('Y-m-d H:i', filemtime($path))
        ));

        $feedEans = [];
        $odrzucone = 0;
        foreach ($feed as $item) {
            $ean = $this->normalizeEan((string)($item['ean'] ?? ''));
            if ($ean === null && trim((string)($item['ean'] ?? '')) !== '') {
                $odrzucone++;
            }
            $feedEans[(string)($item['id'] ?? '')] = $ean;
        }

        $buckets = ['zgodne' => 0, 'nadpisane' => 0, 'uzupelnione' => 0, 'nasze_zostaje' => 0, 'brak_gtin' => 0];
        $zmiany = [];
        $doGs1 = [];

        Product::query()
            ->select(['id', 'external_id', 'product_code', 'ean', 'enabled'])
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($feedEans, &$buckets, &$zmiany, &$doGs1) {
                foreach ($products as $product) {
                    $key = (string)$product->external_id;
                    if (!array_key_exists($key, $feedEans)) {
                        continue; // produkt spoza feedu — nie nasza sprawa
                    }

                    $nowy = $feedEans[$key];
                    $stary = trim((string)$product->ean);

                    if ($nowy === null) {
                        // Dostawca kodu nie ma — zostaje nasz albo nie ma nic.
                        if ($stary !== '') {
                            $buckets['nasze_zostaje']++;
                        } else {
                            $buckets['brak_gtin']++;
                            $doGs1[] = $product;
                        }
                        continue;
                    }

                    if ($stary === $nowy) {
                        $buckets['zgodne']++;
                        continue;
                    }

                    $stary === '' ? $buckets['uzupelnione']++ : $buckets['nadpisane']++;
                    $zmiany[] = ['product' => $product, 'z' => $stary, 'na' => $nowy];
                }
            });

        $this->newLine();
        $this->line('  <fg=green>zgodne, bez ruchu</>          ' . str_pad((string)$buckets['zgodne'], 6, ' ', STR_PAD_LEFT));
        $this->line('  <fg=yellow>nadpisane (nasz 590 -> ich)</> ' . str_pad((string)$buckets['nadpisane'], 6, ' ', STR_PAD_LEFT));
        $this->line('  <fg=yellow>uzupelnione (bylo pusto)</>    ' . str_pad((string)$buckets['uzupelnione'], 6, ' ', STR_PAD_LEFT));
        $this->line('  <fg=gray>nasze zostaje (feed pusty)</>  ' . str_pad((string)$buckets['nasze_zostaje'], 6, ' ', STR_PAD_LEFT));
        $this->line('  <fg=red>bez GTIN po obu stronach</>    ' . str_pad((string)$buckets['brak_gtin'], 6, ' ', STR_PAD_LEFT) . '  <- zadanie GS1');
        if ($odrzucone > 0) {
            $this->line('  <fg=red>odrzucone z feedu (walidacja)</> ' . $odrzucone);
        }

        $limit = (int)$this->option('limit');
        if ($zmiany !== [] && $limit > 0) {
            $this->newLine();
            $this->line('Przyklady zmian:');
            $this->table(
                ['product_code', 'aktywny', 'z', 'na'],
                collect($zmiany)->take($limit)->map(fn ($z) => [
                    $z['product']->product_code,
                    $z['product']->enabled ? 'tak' : 'nie',
                    $z['z'] === '' ? '—' : $z['z'],
                    $z['na'],
                ])->all()
            );
        }

        if ($doGs1 !== []) {
            $this->newLine();
            $this->line('Bez zadnego GTIN (do nadania z naszej puli GS1):');
            foreach (collect($doGs1)->take($limit) as $p) {
                $this->line(sprintf('  %-14s %s', $p->product_code, $p->enabled ? '(aktywny)' : '(wylaczony)'));
            }
        }

        if (!$this->option('apply')) {
            $this->newLine();
            $this->warn('DRY-RUN — nic nie zapisano. Dodaj --apply, zeby wykonac.');
            return self::SUCCESS;
        }

        $this->newLine();
        $bar = $this->output->createProgressBar(count($zmiany));
        $bar->start();
        foreach ($zmiany as $z) {
            // updateQuietly: nie budzimy obserwatorów tłumaczeń ani innych hooków — ruszamy jedno pole.
            $z['product']->updateQuietly(['ean' => $z['na']]);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('Zapisano %d zmian. Payload connectora liczy EAN w hashu, wiec delta-sync sam je przepchnie.', count($zmiany)));

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
