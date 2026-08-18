<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Template;
use App\Services\Ebay\Listing\EbayListingRenderer;
use Illuminate\Console\Command;

/**
 * Podgląd treści oferty eBay złożonej z istniejącego szablonu (`templates`) — etap B.
 * Nic nie wysyła; służy do sprawdzenia tytułu/opisu/zdjęć zanim ruszy publikacja.
 *
 *   php artisan ebay:render-preview 1114 --template=oslonyparetode
 *   php artisan ebay:render-preview --template=oslonyparetofr --audit    # przegląd całego katalogu
 */
class EbayRenderPreview extends Command
{
    protected $signature = 'ebay:render-preview
        {product? : ID produktu (pomiń przy --audit)}
        {--template= : slug szablonu z tabeli templates (np. oslonyparetode)}
        {--audit : przejrzyj CAŁY katalog i policz problemy zamiast pokazywać jeden produkt}
        {--limit=0 : ogranicz audyt do N produktów (0 = wszystkie)}';

    protected $description = 'Podgląd tytułu/opisu/zdjęć oferty eBay z szablonu PIM (nic nie wysyła)';

    public function handle(EbayListingRenderer $renderer): int
    {
        $slug = (string) $this->option('template');
        if ($slug === '') {
            $this->error('Podaj --template=<slug>. Dostępne: '.Template::orderBy('slug')->pluck('slug')->implode(', '));

            return self::FAILURE;
        }

        $template = Template::where('slug', $slug)->first();
        if (! $template) {
            $this->error("Nie ma szablonu „{$slug}”.");

            return self::FAILURE;
        }

        $this->line("Szablon <info>{$template->slug}</info> · locale <info>{$template->locale}</info>");

        return $this->option('audit')
            ? $this->audit($renderer, $template)
            : $this->single($renderer, $template);
    }

    /** Jeden produkt — pełny podgląd tego, co poleci na aukcję. */
    private function single(EbayListingRenderer $renderer, Template $template): int
    {
        $id = (int) $this->argument('product');
        $product = Product::with(['attributeValues.attribute', 'media'])->find($id);
        if (! $product) {
            $this->error("Nie ma produktu #{$id}.");

            return self::FAILURE;
        }

        $title = $renderer->title($template, $product);
        $description = $renderer->description($template, $product);
        $images = $renderer->images($product);

        $this->newLine();
        $this->line("Produkt <info>#{$product->id}</info> · SKU <info>{$product->product_code}</info>");
        $this->newLine();

        $this->line('<comment>TYTUŁ</comment> ('.mb_strlen($title['title']).'/'.EbayListingRenderer::TITLE_MAX.' znaków'
            .($title['truncated'] ? ", <fg=red>przycięty z {$title['original_length']}</>" : '').')');
        $this->line('  '.$title['title']);

        $this->newLine();
        $this->line('<comment>OPIS</comment> ('.strlen($description).' B HTML)');
        $this->line('  '.mb_substr(preg_replace('/\s+/u', ' ', strip_tags($description)) ?? '', 0, 400).'…');

        $this->newLine();
        $this->line('<comment>ZDJĘCIA</comment> ('.count($images).')');
        foreach (array_slice($images, 0, 3) as $u) {
            $this->line('  '.$u);
        }

        $problems = $renderer->problems($template, $product);
        $this->newLine();
        $problems === []
            ? $this->info('Bez zastrzeżeń — treść gotowa do wystawienia.')
            : $this->warn('Do poprawy: '.implode(' · ', $problems));

        return self::SUCCESS;
    }

    /** Cały katalog — ile ofert wyszłoby wadliwych, zanim cokolwiek wyślemy. */
    private function audit(EbayListingRenderer $renderer, Template $template): int
    {
        $limit = (int) $this->option('limit');
        $query = Product::with(['attributeValues.attribute', 'media'])->orderBy('id');
        $total = $limit > 0 ? min($limit, $query->count()) : $query->count();

        $counts = [];
        $samples = [];
        $checked = 0;
        $clean = 0;
        $bar = $this->output->createProgressBar($total);

        $query->chunk(200, function ($chunk) use ($renderer, $template, &$counts, &$samples, &$checked, &$clean, $limit, $bar) {
            foreach ($chunk as $product) {
                if ($limit > 0 && $checked >= $limit) {
                    return false;
                }
                $checked++;
                $bar->advance();

                $problems = $renderer->problems($template, $product);
                if ($problems === []) {
                    $clean++;

                    continue;
                }
                foreach ($problems as $p) {
                    // „tytuł przycięty z 93 do 80" → kubełek bez liczby, inaczej każdy wpis byłby osobny.
                    $key = preg_replace('/\d+/', 'N', $p);
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                    $samples[$key] ??= "#{$product->id} {$product->product_code}";
                }
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->line("Sprawdzono <info>{$checked}</info> produktów · bez zastrzeżeń: <info>{$clean}</info>");

        if ($counts === []) {
            return self::SUCCESS;
        }

        arsort($counts);
        $this->table(['Problem', 'Ile', 'Przykład'], array_map(
            fn ($k) => [$k, $counts[$k], $samples[$k]],
            array_keys($counts)
        ));

        return self::SUCCESS;
    }
}
