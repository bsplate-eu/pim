<?php

namespace App\Services\Integration\Pipelines;

use App\Models\Category;
use App\Models\Integration;
use App\Models\IntegrationCategory;
use App\Models\IntegrationMediaQueueItem;
use App\Models\IntegrationProduct;
use App\Models\IntegrationSource;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Services\Integration\Hashing\PayloadHasher;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogCreatePipeline extends AbstractConnectorPipeline
{
    /** @var array<int, int> PIM category_id => remote external_id */
    private array $categoryMap = [];

    private array $productBuffer = [];
    private array $productBufferMeta = []; // track IntegrationProduct IDs per buffer item

    private IntegrationSource $currentSource;
    private Collection $prices;
    private array $taxMapping = [];

    public function getConnectorName(): string
    {
        return 'catalog_create';
    }

    public function getQueueName(): string
    {
        return 'sync-catalog';
    }

    public function shouldChainAfter(): array
    {
        return ['catalog_delta', 'media'];
    }

    protected function execute(): void
    {
        $this->integration->loadMissing('integrationSources.pricelist', 'integrationSources.template');
        $this->taxMapping = $this->connector->getTaxMapping();

        $this->syncNewCategories();
        $this->syncNewProducts();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  CATEGORIES — create only (no updates)
    // ═══════════════════════════════════════════════════════════════════════════

    private function syncNewCategories(): void
    {
        if (!$this->integration->category_id) {
            $this->logWarning('No category_id set — skipping category sync');
            return;
        }

        $this->loadCategoryMappings();

        $pimTree = Category::descendantsOf($this->integration->category_id)->toTree();
        $rootId  = $this->connector->getRootCategoryId();

        $this->createCategoryTree($rootId, $pimTree);
    }

    private function loadCategoryMappings(): void
    {
        $this->categoryMap = IntegrationCategory::query()
            ->where('integration_id', $this->integration->id)
            ->pluck('external_id', 'category_id')
            ->mapWithKeys(fn ($ext, $cat) => [(int) $cat => (int) $ext])
            ->all();
    }

    private function createCategoryTree(int $remoteParentId, $pimCategories): void
    {
        foreach ($pimCategories as $pimCat) {
            $this->assertNotInterrupted();
            $remoteCatId = $this->categoryMap[(int) $pimCat->id] ?? null;

            if (!$remoteCatId) {
                // New category — create it
                $payload = $this->buildCategoryPayload($pimCat);
                if (empty($payload['name_i18n'])) {
                    $this->logWarning('Category has empty name, skipping', ['pim_id' => $pimCat->id]);
                    continue;
                }

                try {
                    $remoteCatId = $this->connector->createCategory($remoteParentId, $payload);
                    if ($remoteCatId > 0) {
                        $this->categoryMap[(int) $pimCat->id] = $remoteCatId;

                        $hash = PayloadHasher::hashCategoryPayload($payload);
                        IntegrationCategory::updateOrCreate(
                            [
                                'integration_id' => $this->integration->id,
                                'category_id'    => $pimCat->id,
                            ],
                            [
                                'external_id'  => (string) $remoteCatId,
                                'payload_hash' => $hash,
                                'synced_at'    => now(),
                            ]
                        );
                        $this->run->incrementCreated();
                    }
                } catch (\Throwable $e) {
                    $this->logError('createCategory failed', [
                        'pim_id' => $pimCat->id,
                        'error'  => $e->getMessage(),
                    ]);
                    $this->run->addError("cat:{$pimCat->id}", $e->getMessage());
                    continue;
                }
            }

            // Recurse into children
            if ($pimCat->children->isNotEmpty() && $remoteCatId > 0) {
                $this->createCategoryTree($remoteCatId, $pimCat->children);
            }
        }
    }

    private function buildCategoryPayload(Category $category): array
    {
        $filter = fn (array $map) => array_filter(
            $map,
            fn ($v) => trim(strip_tags((string) $v)) !== ''
        );

        // Guard: getTranslations() throws for attrs not in the model's $translatable (this PIM's Category translates only `name`).
        $tr = fn (string $attr) => in_array($attr, $category->getTranslatableAttributes(), true)
            ? $category->getTranslations($attr)
            : [];

        return [
            'name_i18n'         => $filter($tr('name')),
            'short_description' => $filter($tr('lead')),
            'description'       => $filter($tr('long_description')),
            'head_title'        => $filter($tr('meta_title')),
            'meta_description'  => $filter($tr('meta_description')),
            'meta_url'          => $filter($tr('meta_url')),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  PRODUCTS — create only (external_id IS NULL)
    // ═══════════════════════════════════════════════════════════════════════════

    private function syncNewProducts(): void
    {
        $total = IntegrationProduct::query()
            ->where('integration_id', $this->integration->id)
            ->whereNull('external_id')
            ->count();

        $this->run->tick(0, $total);

        if ($total === 0) {
            $this->logInfo('No new products to create');
            return;
        }

        $processed = 0;

        foreach ($this->integration->integrationSources as $source) {
            $this->currentSource = $source;
            app()->setLocale($source->template->locale ?? 'en');

            // Cena eksportowa: reczna (manual_price) gdy > 0, inaczej wlasciwa (price).
            $this->prices = PricelistProduct::exportPriceMap($source->pricelist_id);

            $this->productBuffer = [];
            $this->productBufferMeta = [];

            IntegrationProduct::query()
                ->where('integration_id', $this->integration->id)
                ->where('integration_source_id', $source->id)
                ->whereNull('external_id')
                ->with([
                    'product.media',
                    'product.categories',
                    'product.attributeValues.attribute',
                ])
                ->chunkById(50, function (Collection $chunk) use (&$processed, $total) {
                    foreach ($chunk as $ip) {
                        $this->assertNotInterrupted();
                        $product = $ip->getOverridedProduct();
                        if (!($product instanceof Product) || !$product->enabled) {
                            continue;
                        }

                        $item = $this->buildImportItem($product, $ip);
                        $this->productBuffer[] = $item;
                        $this->productBufferMeta[] = [
                            'ip_id'      => $ip->id,
                            'product_id' => $product->id,
                            'hash'       => PayloadHasher::hashProductPayload($item),
                        ];

                        $processed++;

                        if ($processed % 10 === 0) {
                            $this->run->tick($processed, $total, $product->product_code ?? null);
                        }
                    }

                    if (count($this->productBuffer) >= $this->batchSize) {
                        $this->flushProductBuffer();
                    }
                });

            $this->flushProductBuffer();
        }

        $this->run->tick($processed, $total);
    }

    private function flushProductBuffer(): void
    {
        if (empty($this->productBuffer)) return;
        $this->assertNotInterrupted();

        try {
            $results = $this->connector->importProducts($this->productBuffer);

            foreach ($results as $index => $result) {
                $meta = $this->productBufferMeta[$index] ?? null;
                if (!$meta) continue;

                if (($result['status'] ?? '') === 'ok') {
                    $externalId = $result['data']['product_id'] ?? $result['data']['id_product'] ?? $result['data']['id'] ?? null;

                    IntegrationProduct::where('id', $meta['ip_id'])->update([
                        'external_id'  => $externalId ? (string) $externalId : null,
                        'payload_hash' => $meta['hash'],
                        'state'        => IntegrationProduct::STATE_SYNCED,
                        'synced_at'    => now(),
                    ]);

                    // Queue gallery images for Media connector
                    $this->queueMediaForProduct($meta['product_id'], $externalId);

                    $this->run->incrementCreated();
                } else {
                    $sku    = $result['data']['sku'] ?? $this->productBuffer[$index]['sku'] ?? '?';
                    $errMsg = $result['message'] ?? 'unknown error';
                    $this->logWarning('importProducts item error', ['sku' => $sku, 'error' => $errMsg]);
                    $this->run->addError($sku, $errMsg);

                    IntegrationProduct::where('id', $meta['ip_id'])->update([
                        'state' => IntegrationProduct::STATE_FAILED,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logError('importProducts batch failed', [
                'count' => count($this->productBuffer),
                'error' => $e->getMessage(),
            ]);
            foreach ($this->productBufferMeta as $meta) {
                $this->run->addError("product:{$meta['product_id']}", "Batch failed: {$e->getMessage()}");
            }
        }

        $this->productBuffer = [];
        $this->productBufferMeta = [];
    }

    private function queueMediaForProduct(int $productId, $externalProductId): void
    {
        $product = Product::find($productId);
        if (!$product) return;

        // Tylko zdjęcia z custom_properties.enabled !== false (default: widoczne).
        // Wyłączone zdjęcia są pomijane w eksporcie — konektor traktuje je jak nieistniejące.
        $media = $product->getMedia('images')
            ->filter(fn ($m) => ($m->getCustomProperty('enabled') ?? true) !== false)
            ->sortBy('order_column')->values();

        foreach ($media as $index => $mediaItem) {
            // Skip cover (index 0) — it's included in the product create payload
            if ($index === 0) continue;

            $localPath = $mediaItem->getPath();
            $md5 = null;
            if (is_string($localPath) && is_file($localPath) && is_readable($localPath)) {
                $md5 = md5_file($localPath);
            }

            IntegrationMediaQueueItem::updateOrCreate(
                [
                    'integration_id' => $this->integration->id,
                    'product_id'     => $productId,
                    'media_id'       => $mediaItem->id,
                    'action'         => IntegrationMediaQueueItem::ACTION_UPLOAD,
                ],
                [
                    'external_product_id' => $externalProductId ? (string) $externalProductId : null,
                    'priority'            => $index,
                    'source_url'          => $mediaItem->getFullUrl(),
                    'md5_hash'            => $md5,
                    'state'               => IntegrationMediaQueueItem::STATE_PENDING,
                ]
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  PAYLOAD BUILDER — extracted from SyncService::buildImportItem()
    // ═══════════════════════════════════════════════════════════════════════════

    private function buildImportItem(Product $product, IntegrationProduct $ip): array
    {
        $locale   = $this->currentSource->template->locale ?? 'en';
        $template = $this->currentSource->template;

        $renderedTitle = $renderedDescription = '';
        $renderedMetaTitle = $renderedMetaDesc = '';

        if ($template) {
            try { $renderedTitle       = trim((string) $template->getRenderedTitle($product)); } catch (\Throwable) {}
            try { $renderedDescription = trim((string) $template->getRenderedDescription($product)); } catch (\Throwable) {}
            try { $renderedMetaTitle   = trim((string) $template->getRenderedMetaTitle($product)); } catch (\Throwable) {}
            try { $renderedMetaDesc    = trim((string) $template->getRenderedMetaDescription($product)); } catch (\Throwable) {}
        }

        // Name i18n
        $nameI18n = array_filter(
            $product->getTranslations('name'),
            fn ($v) => trim((string) $v) !== ''
        );
        if ($renderedTitle !== '' && empty($nameI18n[$locale])) {
            $nameI18n[$locale] = $renderedTitle;
        }
        if (empty($nameI18n)) {
            $nameI18n = [$locale => $product->product_code ?: "product-{$product->id}"];
        }

        // SEO fields
        $info1 = array_filter($product->getTranslations('info_1'), fn ($v) => trim(strip_tags((string) $v)) !== '');
        if ($renderedDescription !== '') $info1[$locale] = $renderedDescription;

        $metaTitle = array_filter($product->getTranslations('meta_title'), fn ($v) => trim((string) $v) !== '');
        if (empty($metaTitle[$locale]) && $renderedMetaTitle !== '') {
            $metaTitle[$locale] = $renderedMetaTitle;
        }

        $metaDescription = array_filter($product->getTranslations('meta_description'), fn ($v) => trim((string) $v) !== '');
        if (empty($metaDescription[$locale]) && $renderedMetaDesc !== '') {
            $metaDescription[$locale] = $renderedMetaDesc;
        }

        $metaKeywords = array_filter($product->getTranslations('meta_keywords') ?? [], fn ($v) => trim((string) $v) !== '');

        $metaUrl = array_filter($product->getTranslations('meta_url') ?? [], fn ($v) => trim((string) $v) !== '');
        if (empty($metaUrl[$locale]) && $renderedTitle !== '') {
            $metaUrl[$locale] = Str::slug(strip_tags($renderedTitle));
        }

        // Categories
        $categories = [];
        foreach ($product->categories as $cat) {
            $remoteId = $this->categoryMap[(int) $cat->id] ?? null;
            if ($remoteId) {
                $categories[] = $remoteId;
            }
        }

        // Price
        $price      = (float) ($this->prices->get($product->id) ?? 0);
        $multiplier = (float) ($this->currentSource->multiplier ?? 1);
        $vat        = (float) ($this->currentSource->tax ?? 0);
        $nettoPrice = $vat > 0
            ? round($price * $multiplier / (1 + $vat / 100), 6)
            : round($price * $multiplier, 6);

        $currency = strtoupper((string) optional($this->currentSource->pricelist)->currency ?: 'EUR');

        // Tax
        $taxRulesGroupId = null;
        if (!empty($this->currentSource->tax)) {
            $taxRulesGroupId = $this->taxMapping[(int) $this->currentSource->tax] ?? null;
        }

        $sku = trim((string) $product->product_code) ?: 'pim-' . $product->id;

        // Cover image (first image only for create)
        $images = $this->buildCoverImage($product);

        // Attributes (for LiteCart)
        $attributes = $this->buildAttributes($product);

        $item = [
            'sku'                 => $sku,
            'external_id'         => $ip->external_id,
            'ean'                 => (string) ($product->ean ?? ''),
            'status'              => (int) ($product->enabled ? 1 : 0),
            'name_i18n'           => $nameI18n,
            'quantity'            => $nettoPrice > 0 ? 100 : 0,
            'prices'              => [$currency => $nettoPrice],
            'id_tax_rules_group'  => $taxRulesGroupId,
            'available_for_order' => $nettoPrice > 0 ? 1 : 0,
            'show_price'          => $nettoPrice > 0 ? 1 : 0,
            'weight'              => $product->weight ? (float) $product->weight : null,
            'width'               => $product->width ? (float) $product->width : null,
            'categories'          => array_values(array_unique(array_map('intval', $categories))),
            'manufacturer_name'   => (string) $this->integration->manufacturer,
            'seo'                 => [
                'short_description' => null,
                'description'       => $info1 ?: new \stdClass(),
                'head_title'        => $metaTitle ?: new \stdClass(),
                'meta_description'  => $metaDescription ?: new \stdClass(),
                'meta_keywords'     => $metaKeywords ?: new \stdClass(),
                'meta_url'          => $metaUrl ?: new \stdClass(),
            ],
        ];

        if (count($images) > 0) {
            $item['images_mode'] = 'smart';
            $item['images']      = $images;
        }

        // Zawsze wysyłamy klucz 'attributes' (nawet pusty) — pusta tablica = wyczyść
        // atrybuty w sklepie, gdy w PIM wszystkie usunięto.
        $item['attributes'] = $attributes;

        return array_filter($item, fn ($v) => $v !== null);
    }

    private function buildCoverImage(Product $product): array
    {
        // Cover = pierwsze WIDOCZNE zdjęcie (enabled !== false). Jeśli pierwsze wgrane jest
        // wyłączone, koror cover-em zostaje kolejne widoczne. Brak widocznych → brak cover.
        $cover = $product->getMedia('images')
            ->filter(fn ($m) => ($m->getCustomProperty('enabled') ?? true) !== false)
            ->sortBy('order_column')->first();
        if (!$cover) return [];

        $localPath = $cover->getPath();
        $md5 = null;
        if (is_string($localPath) && is_file($localPath) && is_readable($localPath)) {
            $md5 = md5_file($localPath);
        }
        if (!$md5) {
            $md5 = md5((string) $cover->uuid);
        }

        return [[
            'pim_id'     => (int) $cover->id,
            'filename'   => basename((string) $cover->file_name) ?: "product-{$product->id}-cover.jpg",
            'priority'   => 1,
            'source_url' => $cover->getFullUrl(),
            'md5'        => $md5,
        ]];
    }

    private function buildAttributes(Product $product): array
    {
        $attrs = [];
        foreach ($product->attributeValues as $av) {
            $attribute = $av->attribute;
            if ($attribute === null) continue;

            // Plain accessors only — this PIM's Attribute has no `group` relation; `name` resolves via the translatable accessor.
            $valueName = trim((string) $av->name);
            if ($valueName === '') continue;

            // i18n: etykieta atrybutu (np. "Marka"/"Marke") + wartość, żeby sklep
            // wielojęzyczny dostał atrybuty w każdym języku, nie tylko w locale źródła.
            $groupI18n = array_filter($attribute->getTranslations('name'), fn ($v) => trim((string) $v) !== '');
            $valueI18n = array_filter($av->getTranslations('name'), fn ($v) => trim((string) $v) !== '');

            $attrs[] = [
                'group_name'      => (string) $attribute->name,
                'group_name_i18n' => $groupI18n,
                'value_name'      => $valueName,
                'value_name_i18n' => $valueI18n,
            ];
        }
        return $attrs;
    }

    private function firstTranslation(array $translations, string $fallback = ''): string
    {
        foreach ($translations as $value) {
            $value = trim((string) $value);
            if ($value !== '') return $value;
        }
        return trim($fallback);
    }
}
