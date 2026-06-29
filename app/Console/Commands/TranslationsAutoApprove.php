<?php

namespace App\Console\Commands;

use App\Models\IntegrationProduct;
use App\Models\Product;
use App\Models\Source;
use App\Models\TranslationOverride;
use Illuminate\Console\Command;

/**
 * Automatycznie zatwierdza (enabled=true + overrides.enabled=1) produkty, które mają KOMPLET tłumaczeń.
 *
 * "Komplet" = co najmniej --min-foreign obcych locale (z de/cs/sk/fr/es) ma niepustą wartość różną od PL.
 * Robi dokładnie to samo co ręczny przycisk "Zatwierdź" w review queue, tylko hurtowo i bez klikania.
 *
 * Idempotentne — bierze tylko enabled=false, więc ponowne uruchomienie nie rusza już zatwierdzonych.
 * Można wpiąć w cron po translations:auto-translate, żeby nowe produkty wpadały do eksportu same.
 */
class TranslationsAutoApprove extends Command
{
    protected $signature = 'translations:auto-approve
        {--min-foreign=5 : Ile obcych locale (z 5: de/cs/sk/fr/es) musi mieć tłumaczenie, by zatwierdzić}
        {--source=SumpguardSource : service_class źródła (puste = wszystkie źródła)}
        {--dry-run : Pokaż ile by zatwierdził, bez zapisu}';

    protected $description = 'Automatycznie zatwierdza produkty z kompletnym tłumaczeniem (enabled=true)';

    private const FOREIGN = ['de', 'cs', 'sk', 'fr', 'es'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minForeign = (int) $this->option('min-foreign');

        $query = Product::query()->where('enabled', false)->whereNotNull('source_id');

        if ($src = $this->option('source')) {
            $source = Source::where('service_class', $src)->first();
            if (!$source) {
                $this->error("Nie znaleziono źródła service_class={$src}");
                return self::FAILURE;
            }
            $query->where('source_id', $source->id);
        }

        $total = $query->count();
        $this->info("Produktów wyłączonych do sprawdzenia: {$total}");
        if ($total === 0) {
            return self::SUCCESS;
        }

        $approved = 0;
        $skipped = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($chunk) use (&$approved, &$skipped, $minForeign, $dryRun, $bar) {
            foreach ($chunk as $product) {
                $translations = $product->getTranslations('name');
                $pl = trim((string) ($translations['pl'] ?? ''));
                $covered = 0;
                foreach (self::FOREIGN as $locale) {
                    $value = trim((string) ($translations[$locale] ?? ''));
                    if ($value !== '' && $value !== $pl) {
                        $covered++;
                    }
                }

                if ($covered < $minForeign) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                if (!$dryRun) {
                    $this->approveProduct($product);
                }
                $approved++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->line('=== PODSUMOWANIE ===');
        $this->line(sprintf('  %szatwierdzonych:        %d', $dryRun ? '[dry-run] do ' : '', $approved));
        $this->line(sprintf('  pominiętych (< %d/5):    %d', $minForeign, $skipped));
        if ($dryRun) {
            $this->info('To był podgląd. Uruchom bez --dry-run aby zatwierdzić.');
        }

        return self::SUCCESS;
    }

    private function approveProduct(Product $product): void
    {
        TranslationOverride::$suppressObserver = true;
        try {
            $product->enabled = true;
            $product->save();

            IntegrationProduct::where('product_id', $product->id)
                ->get()
                ->each(function ($ip) {
                    $overrides = $ip->overrides ?? [];
                    $overrides['enabled'] = '1';
                    $ip->overrides = $overrides;
                    $ip->save();
                });
        } finally {
            TranslationOverride::$suppressObserver = false;
        }
    }
}
