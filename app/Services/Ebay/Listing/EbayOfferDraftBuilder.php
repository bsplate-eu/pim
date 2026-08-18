<?php

namespace App\Services\Ebay\Listing;

use App\Models\Ebay\EbayCategory;
use App\Models\Ebay\EbayScheme;
use App\Models\Product;

/**
 * Automat szkicu oferty: produkt PIM + schemat → tytuł, opis, zdjęcia, aspekty (Item Specifics).
 * Wzorzec: App\Services\Allegro\Listing\AllegroOfferDraftBuilder z OMS ARGO.
 *
 * DETERMINISTYCZNY — żadnego AI. Aspekty bierze z `aspect_map` nauczonej kategorii (etap A),
 * treść z szablonu `templates` wskazanego w schemacie (etap B).
 *
 * `notes` niesie ostrzeżenia dla człowieka; te zaczynające się od WYMAGANY blokują publikację
 * (tak samo jak w OMS: `str_contains($note, 'WYMAGAN')`).
 */
class EbayOfferDraftBuilder
{
    public function __construct(private readonly EbayListingRenderer $renderer) {}

    /**
     * @return array{title:string, description:string, images:list<string>,
     *               aspects:array<string,list<string>>, category_id:?string, notes:list<string>}
     */
    public function build(Product $product, EbayScheme $scheme): array
    {
        $notes = [];
        $template = $scheme->template;
        $category = $scheme->category;

        if (! $template) {
            return $this->empty(['WYMAGANY szablon treści — schemat go nie ma.']);
        }

        $titleInfo = $this->renderer->title($template, $product);
        $title = $titleInfo['title'];
        if (trim($title) === '') {
            $notes[] = 'WYMAGANY tytuł — szablon wyrenderował pusty.';
        } elseif ($titleInfo['truncated']) {
            $notes[] = "Tytuł przycięty z {$titleInfo['original_length']} do ".EbayListingRenderer::TITLE_MAX.' znaków.';
        }

        $description = $this->renderer->description($template, $product);
        if (trim(strip_tags($description)) === '') {
            $notes[] = 'WYMAGANY opis — szablon wyrenderował pusty.';
        }

        $images = $this->renderer->images($product);
        if ($images === []) {
            $notes[] = 'WYMAGANE zdjęcie — produkt nie ma żadnego.';
        }

        [$aspects, $aspectNotes] = $this->aspects($product, $category);
        $notes = array_merge($notes, $aspectNotes);

        return [
            'title' => $title,
            'description' => $description,
            'images' => $images,
            'aspects' => $aspects,
            'category_id' => $category?->category_id,
            'notes' => array_values($notes),
        ];
    }

    /**
     * Aspekty z `aspect_map` kategorii → format eBay (`['Hersteller' => ['BSP']]`).
     * eBay chce TABLICY wartości nawet dla aspektu SINGLE.
     *
     * @return array{0: array<string,list<string>>, 1: list<string>}
     */
    private function aspects(Product $product, ?EbayCategory $category): array
    {
        if (! $category) {
            return [[], ['WYMAGANA kategoria eBay — schemat jej nie ma.']];
        }

        $map = $category->aspect_map ?? [];
        $out = [];
        $notes = [];

        foreach ($category->aspects ?? [] as $aspect) {
            $name = $aspect['name'];
            $entry = $map[$name] ?? null;
            if (! is_array($entry)) {
                continue; // brak mapowania = świadomie nie wysyłamy tego aspektu
            }

            $value = $this->resolve($entry, $product);
            if ($value === null || trim($value) === '') {
                if (! empty($aspect['required'])) {
                    $notes[] = "WYMAGANY aspekt „{$name}” nie ma wartości dla tego produktu.";
                }

                continue;
            }

            // MULTI: nasze atrybuty wielowartościowe trzymamy jako „a, b, c" — eBay chce osobnych pozycji.
            $values = ($aspect['cardinality'] ?? 'SINGLE') === 'MULTI'
                ? array_values(array_filter(array_map('trim', explode(',', $value))))
                : [trim($value)];

            $out[$name] = $values;
        }

        // Wymagany aspekt bez ŻADNEGO mapowania — osobny komunikat, bo to brak konfiguracji,
        // nie brak danych produktu.
        foreach ($category->unmappedRequired() as $missing) {
            $notes[] = "WYMAGANY aspekt „{$missing}” nie ma źródła w mapowaniu kategorii.";
        }

        return [$out, $notes];
    }

    /** Wartość aspektu wg wpisu mapowania: stała / atrybut PIM / pole produktu. */
    private function resolve(array $entry, Product $product): ?string
    {
        return match ($entry['source'] ?? '') {
            EbayCategory::SOURCE_FIXED => (string) ($entry['value'] ?? ''),
            EbayCategory::SOURCE_PRODUCT_FIELD => $this->productField($product, (string) ($entry['field'] ?? '')),
            EbayCategory::SOURCE_ATTRIBUTE => $this->attributeValue($product, (int) ($entry['attribute_id'] ?? 0)),
            default => null,
        };
    }

    private function productField(Product $product, string $field): ?string
    {
        if ($field === '') {
            return null;
        }
        $value = $product->{$field} ?? null;

        // `name` jest translatable — surowy dostęp dałby JSON zamiast tekstu.
        if ($field === 'name') {
            $value = $product->getTranslation('name', app()->getLocale());
        }

        return $value === null ? null : (string) $value;
    }

    /** Wartości atrybutu produktu sklejone przecinkiem (rozbijane przy MULTI). */
    private function attributeValue(Product $product, int $attributeId): ?string
    {
        if ($attributeId <= 0) {
            return null;
        }

        $values = $product->attributeValues->where('attribute_id', $attributeId);

        return $values->isEmpty() ? null : $values->implode('name', ', ');
    }

    /** @param list<string> $notes */
    private function empty(array $notes): array
    {
        return [
            'title' => '', 'description' => '', 'images' => [],
            'aspects' => [], 'category_id' => null, 'notes' => $notes,
        ];
    }
}
