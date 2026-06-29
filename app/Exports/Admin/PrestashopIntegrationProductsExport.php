<?php

namespace App\Exports\Admin;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Integration;
use App\Models\PricelistProduct;
use App\Models\IntegrationProduct;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class PrestashopIntegrationProductsExport implements
    FromQuery,
    WithMapping,
    WithHeadings,
    WithCustomCsvSettings,
    WithChunkReading,
    WithStrictNullComparison
{
    /** @var array<int,float> product_id => price */
    private array $prices;

    /** @var array<string,\App\Models\Attribute> slug => model */
    private array $attributesBySlug;

    private array $featuresTranslations = [
        'year' => [
            'en' => 'Year',
            'de' => 'Baujahre',
            'pl' => 'Rocznik',
            'fr' => 'Année',
            'cs' => 'Rok',
        ],
    ];
    private array $categories;

    public function __construct(private Integration $integration)
    {
        // Płaskie tablice są szybsze w hot-path niż kolekcje
        // Cena eksportowa: reczna (manual_price) gdy > 0, inaczej wlasciwa (price).
        $this->prices = PricelistProduct::exportPriceMap($this->integration->pricelist_id)->all();

        $this->attributesBySlug = Attribute::query()
            ->get(['id', 'name', 'slug'])
            ->keyBy('slug')
            ->all();

        $template = $this->integration?->integrationSources?->first()?->template;
        if($template){
            app()->setLocale($template->locale);
        }

        $this->categories = Category::descendantsOf($this->integration->category_id)->pluck('id')->toArray();

    }

    /** CHUNKOWANIE — bez ->get() */
    public function query(): Builder
    {
        $query = IntegrationProduct::query()
            ->where('integration_id', $this->integration->id)
            ->with([
                'product' => function ($q) {
                    $q->with([
                        'attributeValues:id,attribute_id,name',
                        'categories:id,name',
                    ]);
                },
                'product.media',
                'integrationSource.template',
            ]);

        // LiteCart integrations often do not use external_id.
        if ($this->integration->type !== 'litecart') {
            $query->whereNotNull('external_id');
        }

        return $query;
    }

    /** MAPOWANIE jednego rekordu -> wiersz CSV */
    public function map($row): array
    {
        /** @var \App\Models\IntegrationProduct $row */
        if (!$row->integrationSource || !$row->integrationSource->template) {
            return [];
        }

        $template = $row->integrationSource->template;
        $product = $row->getOverridedProduct() ?? $row->product;
        if (!$product) {
            return [];
        }

        // Cena netto (tax excluded)
        $price = $this->getPrice($product?->id);

        $images = $product->getMedia('images')
            ->filter(fn ($m) => ($m->getCustomProperty('enabled') ?? true) !== false)
            ->sortBy('order_column')
            ->implode('original_url', ',');

        $name = $this->safeTemplateRender($template, 'getRenderedTitle', $product, (string)($product->name ?? $product->product_code ?? ''));
        $description = $this->safeTemplateRender($template, 'getRenderedDescription', $product, (string)($product->description ?? ''));
        $metaTitle = $this->safeTemplateRender($template, 'getRenderedMetaTitle', $product, '');
        $metaDescription = $this->safeTemplateRender($template, 'getRenderedMetaDescription', $product, '');
        $descriptionShort = $this->safeTemplateRender($template, 'getRenderedShortDescription', $product, '');

        $categories = optional($product->categories)->whereIn('id', $this->categories)->pluck('name')->filter()->implode(',');

        return [
            'Product ID' => $row->external_id ?: ($product?->product_code ?: $product?->id),
            'Active' => ($product?->enabled && $price > 0) ? 1 : 0,
            'Name' => $this->prepareName($name),
            'Categories' => $categories,
            'Price tax excluded' => $price,
            'Tax rules ID' => 1,
            'Wholesale price' => null,
            'On sale' => null,
            'Discount amount' => null,
            'Discount percent' => null,
            'Discount from' => null,
            'Discount to' => null,
            'Reference #' => $product?->product_code,
            'Supplier reference #' => $product?->external_id,
            'Supplier' => null,
            'Manufacturer' => $this->integration->manufacturer,
            'EAN13' => $product?->ean ?: null,
            'UPC' => null,
            'MPN' => null,
            'Ecotax' => null,
            'Width' => $product?->width,
            'Height' => null,
            'Depth' => null,
            'Weight' => $product?->weight,
            'Delivery time of in-stock products' => null,
            'Delivery time of out-of-stock products with allowed orders' => null,
            'Quantity' => 100,
            'Minimal quantity' => 1,
            'Low stock level' => null,
            'Receive a low stock alert by email' => null,
            'Visibility' => null,
            'Additional shipping cost' => null,
            'Unity' => null,
            'Unit price' => null,
            'Summary' => $descriptionShort,
            'Description' => $description,
            'Tags' => null,
            'Meta title' => $metaTitle,
            'Meta keywords' => null,
            'Meta description' => $metaDescription,
            'URL rewritten' => Str::slug($name),
            'Text when in stock' => null,
            'Text when backorder allowed' => null,
            'Available for order' => 1,
            'Product available date' => null,
            'Product creation date' => null,
            'Show price' => 1,
            'Image URLs' => $images,
            'Image alt texts' => null,
            'Delete existing images' => 1,
            'Feature(Name:Value:Position)' => $this->getFeatures($product, $template->locale),
            'Available online only' => 0,
            'Condition' => 'new',
            'Customizable' => 0,
            'Uploadable files' => 0,
            'Text fields' => 0,
            'Out of stock action' => 0,
            'Virtual product' => 0,
            'File URL' => null,
            'Number of allowed downloads' => null,
            'Expiration date' => null,
            'Number of days' => null,
            'ID / Name of shop' => null,
            'Advanced stock management' => null,
            'Depends On Stock' => null,
            'Warehouse' => null,
            'Acessories' => null,
        ];
    }

    private function prepareName(?string $name): string
    {
        $name = htmlspecialchars_decode((string)$name);
        $name = preg_replace('/[<>;=#{}]/', '-', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return Str::limit($name, 128, '');
    }

    private function safeTemplateRender(object $template, string $method, mixed $product, string $fallback = ''): string
    {
        if (!method_exists($template, $method)) {
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

    private function getPrice(?int $productId): float|int|null
    {
        if (!$productId) {
            return 0;
        }
        $base = $this->prices[$productId] ?? 0;
        $net = $base * $this->integration->multiplier / (1 + $this->integration->vat / 100);
        return (int)ceil($net);
    }

    private function getFeatures($product, string $locale): ?string
    {

        if(config('app.instance') === 'pareto'){
            $make = $this->attributesBySlug['make'] ?? null;

            if(is_null($make)) return null;

            $model = $this->attributesBySlug['model'] ?? null;
            $yFrom = $this->attributesBySlug['year-start'] ?? null;
            $yTo = $this->attributesBySlug['year-stop'] ?? null;

            if (!$make || !$model || !$yFrom || !$yTo || !$product?->attributeValues) {
                return null;
            }

            $byAttrId = $product->attributeValues->keyBy('attribute_id');

            $features = [
                [$make->name, optional($byAttrId->get($make->id))->name, 1],
                [$model->name, optional($byAttrId->get($model->id))->name, 2],
            ];

            $start = (int)($byAttrId->get($yFrom->id)->name ?? 0);
            $stop = (int)($byAttrId->get($yTo->id)->name ?? 0);

            if ($start && $stop && $stop >= $start) {
                $label = $this->featuresTranslations['year'][$locale] ?? 'Year';
                $pos = 3;
                // range potrafi być duży — ale zwykle to kilka/kilkanaście pozycji
                foreach (range($start, $stop) as $year) {
                    $features[] = [$label, $year, $pos++];
                }
            }

            return collect($features)
                ->filter(fn($row) => filled($row[1]))
                ->map(fn($row) => implode(':', $row))
                ->join(',');
        }

        return null;

    }

    public function headings(): array
    {
        return [
            'Product ID', 'Active', 'Name', 'Categories', 'Price tax excluded', 'Tax rules ID',
            'Wholesale price', 'On sale', 'Discount amount', 'Discount percent', 'Discount from', 'Discount to',
            'Reference #', 'Supplier reference #', 'Supplier', 'Manufacturer', 'EAN13', 'UPC', 'MPN', 'Ecotax',
            'Width', 'Height', 'Depth', 'Weight', 'Delivery time of in-stock products',
            'Delivery time of out-of-stock products with allowed orders', 'Quantity', 'Minimal quantity',
            'Low stock level', 'Receive a low stock alert by email', 'Visibility', 'Additional shipping cost',
            'Unity', 'Unit price', 'Summary', 'Description', 'Tags', 'Meta title', 'Meta keywords', 'Meta description',
            'URL rewritten', 'Text when in stock', 'Text when backorder allowed', 'Available for order',
            'Product available date', 'Product creation date', 'Show price', 'Image URLs', 'Image alt texts',
            'Delete existing images', 'Feature(Name:Value:Position)', 'Available online only', 'Condition',
            'Customizable', 'Uploadable files', 'Text fields', 'Out of stock action', 'Virtual product',
            'File URL', 'Number of allowed downloads', 'Expiration date', 'Number of days', 'ID / Name of shop',
            'Advanced stock management', 'Depends On Stock', 'Warehouse', 'Acessories',
        ];
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
            'use_bom' => true,
        ];
    }

    /** domyślny rozmiar chunków do odczytu z DB */
    public function chunkSize(): int
    {
        return 100;
    }
}
