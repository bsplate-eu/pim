<?php

namespace App\Services\Integration\Pipelines;

use App\Models\Category;
use App\Models\IntegrationCategory;
use App\Models\IntegrationProduct;
use App\Models\IntegrationSource;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Services\Integration\Hashing\PayloadHasher;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogDeltaPipeline extends AbstractConnectorPipeline
{
    /** @var array<int, int> PIM category_id => remote external_id */
    private array $categoryMap = [];

    private array $productBuffer = [];
    private array $productBufferMeta = [];

    private IntegrationSource $currentSource;
    private Collection $prices;
    private array $taxMapping = [];

    public function getConnectorName(): string
    {
        return 'catalog_delta';
    }

    public function getQueueName(): string
    {
        return 'sync-catalog';
    }

    public function shouldChainAfter(): array
    {
        return [];
    }

    protected function execute(): void
    {
        $this->integration->loadMissing('integrationSources.pricelist', 'integrationSources.template');
        $this->taxMapping = $this->connector->getTaxMapping();

        $this->loadCategoryMappings();
        $this->syncChangedCategories();
        $this->syncChangedProducts();
        $this->processDeactivations();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  CATEGORIES — update only (already mapped)
    // ═══════════════════════════════════════════════════════════════════════════

    private function loadCategoryMappings(): void
    {
        $this->categoryMap = IntegrationCategory::query()
            ->where('integration_id', $this->integration->id)
            ->pluck('external_id', 'category_id')
            ->mapWithKeys(fn ($ext, $cat) => [(int) $cat => (int) $ext])
            ->all();
    }

    private function syncChangedCategories(): void
    {
        if (!$this->integration->category_id) return;

        $mappings = IntegrationCategory::query()
            ->where('integration_id', $this->integration->id)
            ->get();

        foreach ($mappings as $mapping) {
            $this->assertNotInterrupted();
            $category = Category::find($mapping->category_id);
            if (!$category) continue;

            $payload     = $this->buildCategoryPayload($category);
            $currentHash = PayloadHasher::hashCategoryPayload($payload);

            if ($currentHash === $mapping->payload_hash) {
                $this->run->incrementSkipped();
                continue;
            }

            try {
                $this->connector->updateCategory((int) $mapping->external_id, $payload);
                $mapping->update([
                    'payload_hash' => $currentHash,
                    'synced_at'    => now(),
                ]);
                $this->run->incrementUpdated();
            } catch (\Throwable $e) {
                // Fallback: category deleted from shop — recreate it
                $this->logWarning('updateCategory failed — attempting recreate', [
                    'pim_id'    => $mapping->category_id,
                    'remote_id' => $mapping->external_id,
                    'error'     => $e->getMessage(),
                ]);

                try {
                    $parentRemoteId = $this->resolveParentRemoteId($category);
                    $newRemoteId = $this->connector->createCategory($parentRemoteId, $payload);
                    if ($newRemoteId > 0) {
                        $this->categoryMap[(int) $mapping->category_id] = $newRemoteId;
                        $mapping->update([
                            'external_id'  => (string) $newRemoteId,
                            'payload_hash' => $currentHash,
                            'synced_at'    => now(),
                        ]);
                        $this->run->incrementCreated();
                    }
                } catch (\Throwable $e2) {
                    $this->logError('recreateCategory also failed', [
                        'pim_id' => $mapping->category_id,
                        'error'  => $e2->getMessage(),
                    ]);
                    $this->run->addError("cat:{$mapping->category_id}", $e2->getMessage());
                }
            }
        }
    }

    private function resolveParentRemoteId(Category $category): int
    {
        if ($category->parent_id) {
            return $this->categoryMap[(int) $category->parent_id]
                ?? (int) (IntegrationCategory::query()
                    ->where('integration_id', $this->integration->id)
                    ->where('category_id', $category->parent_id)
                    ->value('external_id') ?? $this->connector->getRootCategoryId());
        }
        return $this->connector->getRootCategoryId();
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
            // Bez pim_id connector OpenCart nie rozpozna istniejącej kategorii (addCategory)
            // → tworzy duplikaty i nie zapisuje oc_pim_category_link.
            'pim_id'            => (int) $category->id,
            'name_i18n'         => $filter($tr('name')),
            'short_description' => $filter($tr('lead')),
            'description'       => $filter($tr('long_description')),
            'head_title'        => $filter($tr('meta_title')),
            'meta_description'  => $filter($tr('meta_description')),
            'meta_url'          => $filter($tr('meta_url')),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  PRODUCTS — update only (external_id IS NOT NULL, hash changed)
    // ═══════════════════════════════════════════════════════════════════════════

    private function syncChangedProducts(): void
    {
        $total = IntegrationProduct::query()
            ->where('integration_id', $this->integration->id)
            ->whereNotNull('external_id')
            ->where('state', '!=', IntegrationProduct::STATE_PENDING_DELETE)
            ->count();

        $this->run->tick(0, $total);
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
                ->whereNotNull('external_id')
                ->where('state', '!=', IntegrationProduct::STATE_PENDING_DELETE)
                ->with([
                    'product.media',
                    'product.categories',
                    'product.attributeValues.attribute',
                ])
                ->chunkById(50, function (Collection $chunk) use (&$processed, $total) {
                    foreach ($chunk as $ip) {
                        $this->assertNotInterrupted();
                        $product = $ip->getOverridedProduct();
                        if (!($product instanceof Product)) continue;

                        $item        = $this->buildImportItem($product, $ip);
                        $currentHash = PayloadHasher::hashProductPayload($item);

                        if ($currentHash === $ip->payload_hash) {
                            $this->run->incrementSkipped();
                            $processed++;
                            continue;
                        }

                        $this->productBuffer[]     = $item;
                        $this->productBufferMeta[] = [
                            'ip_id' => $ip->id,
                            'hash'  => $currentHash,
                            'sku'   => $item['sku'] ?? '?',
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
                    $update = [
                        'payload_hash' => $meta['hash'],
                        'state'        => IntegrationProduct::STATE_SYNCED,
                        'synced_at'    => now(),
                    ];
                    // Connector mógł odtworzyć produkt (usunięty ze sklepu) → nowy id_product.
                    // OpenCart zwraca product_id na top-level; Presta/LiteCart pod 'data'.
                    $returnedId = $result['product_id'] ?? $result['id_product'] ?? $result['data']['product_id'] ?? $result['data']['id_product'] ?? null;
                    if ($returnedId) {
                        $update['external_id'] = (string) $returnedId;
                    }
                    IntegrationProduct::where('id', $meta['ip_id'])->update($update);
                    $this->run->incrementUpdated();
                } else {
                    $errMsg = $result['message'] ?? 'unknown error';
                    $this->logWarning('Delta importProducts item error', ['sku' => $meta['sku'], 'error' => $errMsg]);
                    $this->run->addError($meta['sku'], $errMsg);
                }
            }
        } catch (\Throwable $e) {
            $this->logError('Delta importProducts batch failed', [
                'count' => count($this->productBuffer),
                'error' => $e->getMessage(),
            ]);
            foreach ($this->productBufferMeta as $meta) {
                $this->run->addError($meta['sku'], "Batch failed: {$e->getMessage()}");
            }
        }

        $this->productBuffer = [];
        $this->productBufferMeta = [];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  DEACTIVATIONS — products marked for deletion
    // ═══════════════════════════════════════════════════════════════════════════

    private function processDeactivations(): void
    {
        $toDeactivate = IntegrationProduct::query()
            ->where('integration_id', $this->integration->id)
            ->where('state', IntegrationProduct::STATE_PENDING_DELETE)
            ->whereNotNull('external_id')
            ->get();

        foreach ($toDeactivate as $ip) {
            $this->assertNotInterrupted();
            try {
                $this->connector->importProducts([[
                    'pim_id'      => (int) $ip->product_id,
                    'external_id' => $ip->external_id,
                    'sku'         => trim((string) $ip->product?->product_code) ?: 'pim-' . $ip->product_id,
                    'status'      => 0,
                ]]);

                $ip->delete();
                $this->logInfo('Product deactivated in remote shop', ['product_id' => $ip->product_id]);
            } catch (\Throwable $e) {
                $this->logWarning('Deactivation failed', [
                    'product_id' => $ip->product_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  PAYLOAD BUILDER — same as CatalogCreate (without images)
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

        $nameI18n = array_filter($product->getTranslations('name'), fn ($v) => trim((string) $v) !== '');
        if ($renderedTitle !== '' && empty($nameI18n[$locale])) {
            $nameI18n[$locale] = $renderedTitle;
        }
        if (empty($nameI18n)) {
            $nameI18n = [$locale => $product->product_code ?: "product-{$product->id}"];
        }

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

        $categories = [];
        foreach ($product->categories as $cat) {
            $remoteId = $this->categoryMap[(int) $cat->id] ?? null;
            if ($remoteId) $categories[] = $remoteId;
        }

        $price      = (float) ($this->prices->get($product->id) ?? 0);
        $multiplier = (float) ($this->currentSource->multiplier ?? 1);
        $vat        = (float) ($this->currentSource->tax ?? 0);
        $nettoPrice = $vat > 0
            ? round($price * $multiplier / (1 + $vat / 100), 6)
            : round($price * $multiplier, 6);

        $currency = strtoupper((string) optional($this->currentSource->pricelist)->currency ?: 'EUR');

        $taxRulesGroupId = null;
        if (!empty($this->currentSource->tax)) {
            $taxRulesGroupId = $this->taxMapping[(int) $this->currentSource->tax] ?? null;
        }

        $sku = trim((string) $product->product_code) ?: 'pim-' . $product->id;

        $attributes = $this->buildAttributes($product);

        $item = [
            'sku'                 => $sku,
            'pim_id'              => (int) $product->id, // wymagane przez connector OpenCart (mapa oc_pim_product_link)
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

        // Zawsze wysyłamy klucz 'attributes' (nawet pusty) — pusta tablica = wyczyść
        // atrybuty w sklepie, gdy w PIM wszystkie usunięto. Zmiana atrybutów jest też
        // w hashu payloadu (PayloadHasher), więc delta wykryje ją i wypchnie.
        $item['attributes'] = $attributes;

        return array_filter($item, fn ($v) => $v !== null);
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
                // Stabilne kody (slug) — dla fasetowego findera po stronie sklepu (Argo Faset):
                // mapowanie make/model/year-start/year-stop niezależne od języka etykiety.
                'group_code'      => (string) $attribute->slug,
                'group_name'      => (string) $attribute->name,
                'group_name_i18n' => $groupI18n,
                'value_code'      => (string) $av->slug,
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
