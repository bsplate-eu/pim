<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\TranslationOverride;
use App\Services\ProductTranslationComposer;
use Illuminate\Console\Command;

/**
 * Uruchamia ProductTranslationComposer dla wskazanych produktów (bulk auto-fill z matrycy).
 *
 * Domyślnie leci po WSZYSTKICH produktach źródła Sumpguard. Composer pomija sloty
 * już zablokowane (manual/sheet_import/auto_matrix), więc komenda jest idempotentna.
 */
class TranslationsAutoTranslate extends Command
{
    protected $signature = 'translations:auto-translate
        {--product= : ID konkretnego produktu (jeden)}
        {--source=SumpguardSource : service_class źródła (Source.service_class)}
        {--missing-only : Tylko produkty, które NIE mają jeszcze żadnego auto_matrix/sheet_import lock dla `name`}
        {--limit=0 : Limit produktów (0 = wszystkie)}
        {--dry-run : Pokaż statystyki bez zapisu}';

    protected $description = 'Wypełnia tłumaczenia produktów (6 lokali + 5 kont Allegro) z matrycy fraz';

    public function handle(ProductTranslationComposer $composer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $query = Product::with('attributeValues.attribute');

        if ($pid = $this->option('product')) {
            $query->where('id', (int) $pid);
        } else {
            $sourceClass = $this->option('source');
            $source = \App\Models\Source::where('service_class', $sourceClass)->first();
            if (!$source) {
                $this->error("Nie znaleziono Source z service_class={$sourceClass}");
                return self::FAILURE;
            }
            $query->where('source_id', $source->id);
        }

        if ($this->option('missing-only')) {
            $lockedIds = TranslationOverride::query()
                ->where('translatable_type', (new Product())->getMorphClass())
                ->where('field', 'name')
                ->whereIn('source', TranslationOverride::LOCKING_SOURCES)
                ->distinct()
                ->pluck('translatable_id');
            $query->whereNotIn('id', $lockedIds);
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) $query->limit($limit);

        $total = $query->count();
        $this->info("Produktów do przetworzenia: {$total}");
        if ($total === 0) return self::SUCCESS;

        $stats = [
            'matched'              => 0,
            'unmatched'            => 0,
            'applied_locales'      => 0,
            'applied_integrations' => 0,
            'skipped_locked'       => 0,
        ];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(50, function ($products) use ($composer, $dryRun, &$stats, $bar) {
            foreach ($products as $product) {
                if ($dryRun) {
                    $proposal = $composer->compose($product);
                    if ($proposal['matched']) $stats['matched']++;
                    else $stats['unmatched']++;
                } else {
                    $s = $composer->apply($product);
                    if ($s['matched']) $stats['matched']++;
                    else $stats['unmatched']++;
                    $stats['applied_locales']      += $s['applied_locales'];
                    $stats['applied_integrations'] += $s['applied_integrations'];
                    $stats['skipped_locked']       += $s['skipped_locked'];
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->line('=== PODSUMOWANIE ===');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-22s %d', $k, $v));
        }
        return self::SUCCESS;
    }
}
