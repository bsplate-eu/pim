<?php

namespace App\Exports\Admin;

use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\IntegrationSource;
use App\Models\PricelistProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Throwable;

class SellyIntegrationProductsExport implements FromCollection, WithHeadings, WithCustomCsvSettings
{
    /** Stała ilość magazynowa w feedzie — PIM nie trzyma realnych stanów. */
    public const DEFAULT_QUANTITY = 100;

    /** Kategoria zbiorcza dla produktów BEZ przypisanej kategorii. */
    public const FALLBACK_CATEGORY = 'Pozostałe';

    private Collection $prices;

    public function __construct(private Integration $integration)
    {
        $this->integration->load('integrationSources.template');
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection(): Collection
    {
        $result = collect();

        $this->integration->integrationSources->each(function ($integrationSource) use (&$result) {
            if (!$integrationSource->template || !$integrationSource->pricelist) {
                return;
            }

            app()->setLocale($integrationSource->template->locale);
            $this->prices = PricelistProduct::where('pricelist_id', $integrationSource->pricelist->id)
                ->selectRaw('product_id, ' . PricelistProduct::EXPORT_PRICE_SQL . ' as price')
                ->get()->keyBy('product_id');

            $integration_source_result = IntegrationProduct::with('product.media', 'product.attributeValues', 'product.categories')
                ->where('integration_id', $this->integration->id)
                ->where('integration_source_id', $integrationSource->id)
                ->get()
                ->map(fn (IntegrationProduct $model) => $this->map($model, $integrationSource))
                ->filter(fn ($row) => !empty($row)); // pomiń wiersze bez produktu

            $result = $result->merge($integration_source_result);
        });

        return $result;
    }

    private function map(IntegrationProduct $model, IntegrationSource $integrationSource)
    {
        $product = $model->getOverridedProduct() ?? $model->product;
        if (!$product) {
            return [];
        }
        $price = $this->getPrice($product, $integrationSource->multiplier);

        $images = $product->getMedia('images')
            ->filter(fn ($m) => ($m->getCustomProperty('enabled') ?? true) !== false)
            ->sortBy('order_column')
            ->pluck('original_url')
            ->toArray();

        $name = $this->safeTemplateRender($integrationSource, 'getRenderedTitle', $product, (string)($product->name ?? $product->product_code ?? ''));
        $description = $this->safeTemplateRender($integrationSource, 'getRenderedDescription', $product, (string)($product->description ?? ''));
        $description_short = $this->safeTemplateRender($integrationSource, 'getRenderedShortDescription', $product, '');

        $category = $product->categories->implode('name', '|');
        if ($category === '') {
            $category = self::FALLBACK_CATEGORY; // brak kategorii → kategoria zbiorcza
        }

        $data = [
            "Kod_importu" => $product->external_id,
            "Producent" => $this->integration->manufacturer,
            "Kod_producenta" => $product->product_code,
            "Nazwa_produktu" => $this->prepareName($name),
            "Nazwa dodatkowa" => null,
            "Tekst promocyjny" => null,
            "Kategoria_sciezka" => $category,
            "Kategoria_ID" => md5($category),
            "Opis_HTML" => $description,
            "Opis_dodatkowy_HTML" => $description_short,
            "Cena_brutto" => $price,
            "Stawka VAT" => (string)$integrationSource->tax,
            "Ilosc" => self::DEFAULT_QUANTITY,
            "Wyświetlanie" => (int)$product->enabled,
            "Zdjecie_glowne" => array_shift($images),
            "Zdjecia" => implode(',',$images),
        ];

        return $data;
    }

    private function prepareName(string $name)
    {
        $name = htmlspecialchars_decode($name);
        $name = preg_replace('/[<>;=#{}]/', '-', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return Str::limit($name, 128, '');
    }

    private function getPrice($product, $multiplier = 1)
    {
        $price = $this->prices->get($product->id)->price ?? 0;
        return ceil($price * $multiplier);
    }

    private function safeTemplateRender(IntegrationSource $integrationSource, string $method, mixed $product, string $fallback = ''): string
    {
        $template = $integrationSource->template;
        if (!$template || !method_exists($template, $method)) {
            return $fallback;
        }

        try {
            set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
                if (!(error_reporting() & $severity)) {
                    return false;
                }
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });

            return (string)$template->{$method}($product);
        } catch (Throwable $e) {
            report($e);
            return $fallback;
        } finally {
            restore_error_handler();
        }
    }

    public function headings(): array
    {
        return [
            "Kod importu",
            "Producent",
            "Kod producenta",
            "Nazwa produktu",
            "Nazwa dodatkowa",
            "Tekst promocyjny",
            "Kategoria ścieżka",
            "Kategoria ID",
            "Opis HTML",
            "Opis dodatkowy HTML",
            "Cena brutto",
            "Stawka VAT",
            "Ilość",
            "Wyświetlanie",
            "Zdjęcie główne",
            "Zdjęcia",
        ];
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
            'use_bom' => true,
        ];
    }
}
