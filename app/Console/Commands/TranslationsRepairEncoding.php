<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\TranslationOverride;
use Illuminate\Console\Command;

/**
 * Naprawia uszkodzone polskie znaki (ł/ó/ę/… → "?") w products.name.
 *
 * Przyczyna: historyczny import Sumpguarda zapisał dane złym charsetem połączenia,
 * przez co diakrytyki (2-bajtowe UTF-8) zostały zamienione na literalne "?" (lossy).
 * Aktualne połączenie zapisuje UTF-8 poprawnie (dowód: import z arkusza jest czysty).
 *
 * Źródło prawdy: per-locale feedy Sumpguarda w storage/app/sumpguard/{locale}.json (czysty UTF-8).
 *
 * Naprawiamy TYLKO sloty które:
 *   - zawierają "?" (są uszkodzone), ORAZ
 *   - NIE są zablokowane w translation_overrides (manual/sheet_import/auto_matrix).
 * Czyli ręczne tłumaczenia i te z arkusza zostają nietknięte.
 */
class TranslationsRepairEncoding extends Command
{
    protected $signature = 'translations:repair-encoding
        {--dry-run : Pokaż co zostanie naprawione, bez zapisu}
        {--locale= : Napraw tylko ten locale (domyślnie wszystkie 6 matrycowych)}
        {--clear-non-matrix : Zamiast naprawiać — WYCZYŚĆ uszkodzone sloty w lokalach SPOZA matrycy (en/lt/it/lv/et/ro/hu/bg)}';

    protected $description = 'Naprawia uszkodzone polskie znaki w products.name (z feedu) lub czyści śmieci w lokalach spoza matrycy';

    /** locale matrycy w PIM → plik feedu (cs używa cz.json). Te naprawiamy z feedu. */
    private const LOCALE_FEED = [
        'pl' => 'pl',
        'de' => 'de',
        'cs' => 'cz',
        'sk' => 'sk',
        'fr' => 'fr',
        'es' => 'es',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyLocale = $this->option('locale');

        if ($this->option('clear-non-matrix')) {
            return $this->clearNonMatrix($dryRun);
        }

        $feeds = $this->loadFeeds($onlyLocale);
        if (empty($feeds)) {
            $this->error('Brak feedów do naprawy.');
            return self::FAILURE;
        }
        $this->info('Załadowano feedy: ' . implode(', ', array_keys($feeds)));

        $locales = array_keys($feeds);

        // Produkty z "?" w którymkolwiek slocie name
        $orConds = [];
        foreach ($locales as $l) {
            $orConds[] = "JSON_EXTRACT(name, '$.\"{$l}\"') LIKE '%?%'";
        }
        $query = Product::whereRaw('(' . implode(' OR ', $orConds) . ')');

        $total = $query->count();
        $this->info("Produktów z uszkodzonymi znakami: {$total}");
        if ($total === 0) return self::SUCCESS;

        $stats = ['products_touched' => 0, 'slots_fixed' => 0, 'slots_locked_skip' => 0, 'slots_no_feed' => 0];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($products) use ($feeds, $locales, $dryRun, &$stats, $bar) {
            foreach ($products as $product) {
                $changed = false;
                $lockedLocales = TranslationOverride::lockedLocales($product, 'name');

                foreach ($locales as $locale) {
                    $current = $product->getTranslation('name', $locale, false);
                    if ($current === null || $current === '' || !str_contains($current, '?')) {
                        continue; // slot pusty lub nieuszkodzony
                    }
                    if (in_array($locale, $lockedLocales, true)) {
                        $stats['slots_locked_skip']++;
                        continue;
                    }
                    $clean = $feeds[$locale][$product->external_id] ?? null;
                    if ($clean === null || $clean === '') {
                        $stats['slots_no_feed']++;
                        continue;
                    }
                    if (!$dryRun) {
                        $product->setTranslation('name', $locale, $clean);
                    }
                    $stats['slots_fixed']++;
                    $changed = true;
                }

                if ($changed) {
                    $stats['products_touched']++;
                    if (!$dryRun) {
                        TranslationOverride::$suppressObserver = true;
                        try {
                            $product->save();
                        } finally {
                            TranslationOverride::$suppressObserver = false;
                        }
                    }
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->line('=== PODSUMOWANIE' . ($dryRun ? ' (DRY RUN)' : '') . ' ===');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-20s %d', $k, $v));
        }
        return self::SUCCESS;
    }

    /**
     * Czyści uszkodzone sloty (zawierające "?") w lokalach SPOZA matrycy.
     * Matryca pokrywa pl/de/cs/sk/fr/es — reszta available_locales (en/lt/it/lv/et/ro/hu/bg)
     * trzyma tylko popsuty polski fallback z Sumpguarda → kasujemy żeby były puste.
     * Respektuje locki (manual/sheet_import/auto_matrix).
     */
    private function clearNonMatrix(bool $dryRun): int
    {
        $allLocales = app(\App\Settings\GeneralSettings::class)->available_locales;
        $matrixLocales = array_keys(self::LOCALE_FEED);
        $nonMatrix = array_values(array_diff($allLocales, $matrixLocales));

        if (empty($nonMatrix)) {
            $this->info('Brak lokali spoza matrycy.');
            return self::SUCCESS;
        }
        $this->info('Locale spoza matrycy do wyczyszczenia: ' . implode(', ', $nonMatrix));

        $orConds = [];
        foreach ($nonMatrix as $l) {
            $orConds[] = "JSON_EXTRACT(name, '$.\"{$l}\"') LIKE '%?%'";
        }
        $query = Product::whereRaw('(' . implode(' OR ', $orConds) . ')');
        $total = $query->count();
        $this->info("Produktów z uszkodzonymi slotami spoza matrycy: {$total}");
        if ($total === 0) return self::SUCCESS;

        $stats = ['products_touched' => 0, 'slots_cleared' => 0, 'slots_locked_skip' => 0];
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($products) use ($nonMatrix, $dryRun, &$stats, $bar) {
            foreach ($products as $product) {
                $changed = false;
                $lockedLocales = TranslationOverride::lockedLocales($product, 'name');
                foreach ($nonMatrix as $locale) {
                    $current = $product->getTranslation('name', $locale, false);
                    if ($current === null || $current === '' || !str_contains($current, '?')) {
                        continue;
                    }
                    if (in_array($locale, $lockedLocales, true)) {
                        $stats['slots_locked_skip']++;
                        continue;
                    }
                    if (!$dryRun) {
                        $product->forgetTranslation('name', $locale);
                    }
                    $stats['slots_cleared']++;
                    $changed = true;
                }
                if ($changed) {
                    $stats['products_touched']++;
                    if (!$dryRun) {
                        TranslationOverride::$suppressObserver = true;
                        try {
                            $product->save();
                        } finally {
                            TranslationOverride::$suppressObserver = false;
                        }
                    }
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->line('=== CZYSZCZENIE' . ($dryRun ? ' (DRY RUN)' : '') . ' ===');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-20s %d', $k, $v));
        }
        return self::SUCCESS;
    }

    private function loadFeeds(?string $onlyLocale): array
    {
        $feeds = [];
        foreach (self::LOCALE_FEED as $locale => $file) {
            if ($onlyLocale && $locale !== $onlyLocale) continue;
            $path = storage_path("app/sumpguard/{$file}.json");
            if (!file_exists($path)) {
                $this->warn("Brak feedu: {$path}");
                continue;
            }
            $json = json_decode(file_get_contents($path), true);
            if (!is_array($json)) continue;
            $keyed = [];
            foreach ($json as $item) {
                if (!isset($item['id'])) continue;
                $keyed[$item['id']] = $this->vauxhallClear((string) ($item['name'] ?? ''));
            }
            $feeds[$locale] = $keyed;
        }
        return $feeds;
    }

    private function vauxhallClear(string $string): string
    {
        return str_replace('Vauxhall', 'Opel', $string);
    }
}
