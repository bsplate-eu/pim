<?php

namespace App\Console\Commands;

use App\Models\Ebay\EbayOffer;
use App\Services\Scrap\ProductMatcher;
use Illuminate\Console\Command;

/**
 * Przemapowanie JUŻ przypisanych aukcji (ebay_offers) po naprawie matchera.
 * Potrzebne, bo auto-przypisanie rusza wyłącznie oferty z pustym product_id — samo z siebie
 * nie poprawi tych, które dostały zły produkt wcześniej (zduplikowany product_code:
 * 13.121 = Mazda 3/6/Atenza/Axela/CX5, wygrywał kandydat o najniższym id).
 *
 * Domyślnie DRY-RUN (tylko raport). Zapis dopiero z --apply.
 * NIE dotyka przypisań ręcznych (match_type = 'manual') ani samego eBay — zmienia tylko mapowanie w bazie.
 */
class EbayRematchProducts extends Command
{
    protected $signature = 'ebay:rematch
        {--apply : Zapisz zmiany (bez tej flagi tylko raport)}
        {--marketplace= : Ogranicz do rynku, np. EBAY_DE}
        {--limit=25 : Ile przykładów wypisać}';

    protected $description = 'Przelicza przypisanie aukcji eBay do produktów (SKU + tytuł). Domyślnie dry-run.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $market = $this->option('marketplace');
        $limit = (int) $this->option('limit');

        $matcher = new ProductMatcher();
        $byCode = $matcher->candidatesByCode();
        $this->info('Kodów w katalogu: ' . count($byCode));

        $checked = 0; $changed = 0; $cleared = 0; $same = 0; $skippedManual = 0;
        $examples = [];

        EbayOffer::query()
            ->when($market, fn ($q) => $q->where('marketplace', $market))
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->chunkById(500, function ($offers) use ($matcher, $byCode, $apply, $limit, &$checked, &$changed, &$cleared, &$same, &$skippedManual, &$examples) {
                foreach ($offers as $o) {
                    if ($o->match_type === 'manual') {   // ręcznego wyboru nie nadpisujemy
                        $skippedManual++;
                        continue;
                    }
                    $checked++;
                    $new = $matcher->pickForCode($byCode, $o->sku, $o->title)['id'];

                    if ($new === $o->product_id) { $same++; continue; }

                    if ($new === null) { $cleared++; continue; }  // nie kasujemy istniejącego przypisania

                    $changed++;
                    if (count($examples) < $limit) {
                        $examples[] = [$o->marketplace, $o->sku, mb_substr((string) $o->title, 0, 46), $o->product_id, $new];
                    }
                    if ($apply) {
                        $o->forceFill(['product_id' => $new, 'match_type' => 'auto'])->save();
                    }
                }
            });

        if ($examples) {
            $this->newLine();
            $this->table(['Rynek', 'SKU', 'Tytuł', 'było', 'będzie'], $examples);
        }

        $this->newLine();
        $this->line("Sprawdzonych:      {$checked}");
        $this->line("Bez zmian:         {$same}");
        $this->line("Pominięte ręczne:  {$skippedManual}");
        $this->line("Bez dopasowania:   {$cleared} (zostawione bez zmian)");
        $this->{$changed > 0 ? 'warn' : 'line'}(($apply ? 'ZMIENIONYCH:       ' : 'DO ZMIANY:         ') . $changed);

        if (! $apply && $changed > 0) {
            $this->newLine();
            $this->comment('To był podgląd. Aby zapisać: php artisan ebay:rematch --apply');
        }

        return self::SUCCESS;
    }
}
