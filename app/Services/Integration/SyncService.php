<?php

namespace App\Services\Integration;

use App\Contracts\ShopConnectorInterface;
use App\Models\Blog;
use App\Models\BlogArticle;
use App\Models\Category;
use App\Models\Integration;
use App\Models\IntegrationCategory;
use App\Models\IntegrationProduct;
use App\Models\IntegrationSource;
use App\Models\IntegrationSyncLog;
use App\Models\PricelistProduct;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncService
{
    private ShopConnectorInterface $connector;
    private Integration $integration;

    /** @var array<int, int> PIM category_id => remote external_id */
    private array $categoryMap = [];

    private array $productBuffer = [];
    private ?IntegrationSyncLog $syncLog = null;
    private int $batchSize;

    private IntegrationSource $currentSource;
    private Collection $prices;
    private array $taxMapping = [];

    public function __construct(Integration $integration, ShopConnectorInterface $connector)
    {
        $this->integration = $integration;
        $this->connector   = $connector;

        $this->integration->loadMissing('integrationSources.pricelist', 'integrationSources.template');

        $batchKey = match ($integration->type) {
            'prestashop' => 'prestashop_batch_size',
            'litecart'   => 'litecart_batch_size',
            'opencart'   => 'opencart_batch_size',
            default      => 'prestashop_batch_size',
        };
        $this->batchSize = max(1, (int) config("integrations.{$batchKey}", 10));
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  CATEGORIES
    // ═══════════════════════════════════════════════════════════════════════════

    public function syncCategories(): void
    {
        if (!$this->integration->category_id) {
            Log::warning('SyncService: integration has no category_id — skipping category sync', [
                'integration_id' => $this->integration->id,
            ]);
            return;
        }

        try {
            // Load existing ID mappings from DB
            $this->loadCategoryMappings();

            // Walk PIM category tree and sync
            $pimTree = Category::descendantsOf($this->integration->category_id)->toTree();
            $rootId  = $this->connector->getRootCategoryId();

            $this->syncCategoryTree($rootId, $pimTree);

        } catch (\Throwable $e) {
            Log::error('SyncService: syncCategories failed', [
                'integration_id' => $this->integration->id,
                'message'        => $e->getMessage(),
            ]);
        }
    }

    private function loadCategoryMappings(): void
    {
        $mappings = IntegrationCategory::query()
            ->where('integration_id', $this->integration->id)
            ->pluck('external_id', 'category_id');

        $this->categoryMap = $mappings->mapWithKeys(
            fn ($externalId, $categoryId) => [(int) $categoryId => (int) $externalId]
        )->all();
    }

    private function syncCategoryTree(int $remoteParentId, $pimCategories): void
    {
        foreach ($pimCategories as $pimCat) {
            $payload = $this->buildCategoryPayload($pimCat);

            if (empty($payload['name_i18n'])) {
                Log::warning('SyncService: category has empty name', ['pim_id' => $pimCat->id]);
                continue;
            }

            $remoteCatId = $this->categoryMap[(int) $pimCat->id] ?? null;

            if ($remoteCatId) {
                // Existing mapping — try update, fall back to create if not found
                try {
                    $this->connector->updateCategory($remoteCatId, $payload);
                    $this->saveCategoryMapping((int) $pimCat->id, (string) $remoteCatId);
                } catch (\Throwable $e) {
                    Log::warning('SyncService: updateCategory failed — retrying as create', [
                        'pim_id'    => $pimCat->id,
                        'remote_id' => $remoteCatId,
                        'message'   => $e->getMessage(),
                    ]);
                    // Category no longer exists remotely — create it fresh
                    try {
                        $remoteCatId = $this->connector->createCategory($remoteParentId, $payload);
                        if ($remoteCatId > 0) {
                            $this->categoryMap[(int) $pimCat->id] = $remoteCatId;
                            $this->saveCategoryMapping((int) $pimCat->id, (string) $remoteCatId);
                        }
                    } catch (\Throwable $e2) {
                        Log::error('SyncService: createCategory fallback failed', [
                            'pim_id'  => $pimCat->id,
                            'message' => $e2->getMessage(),
                        ]);
                        continue;
                    }
                }
            } else {
                // No mapping — create
                try {
                    $remoteCatId = $this->connector->createCategory($remoteParentId, $payload);
                    if ($remoteCatId > 0) {
                        $this->categoryMap[(int) $pimCat->id] = $remoteCatId;
                        $this->saveCategoryMapping((int) $pimCat->id, (string) $remoteCatId);
                    }
                } catch (\Throwable $e) {
                    Log::error('SyncService: createCategory failed', [
                        'pim_id'  => $pimCat->id,
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            // Recurse into children
            if ($pimCat->children->isNotEmpty() && $remoteCatId > 0) {
                $this->syncCategoryTree($remoteCatId, $pimCat->children);
            }
        }
    }

    private function saveCategoryMapping(int $categoryId, string $externalId): void
    {
        IntegrationCategory::updateOrCreate(
            [
                'integration_id' => $this->integration->id,
                'category_id'    => $categoryId,
            ],
            [
                'external_id' => $externalId,
                'synced_at'   => now(),
            ]
        );
    }

    private function buildCategoryPayload(Category $category): array
    {
        $filter = fn (array $map) => array_filter(
            $map,
            fn ($v) => trim(strip_tags((string) $v)) !== ''
        );

        $nameI18n = $filter($category->getTranslations('name'));

        return [
            'name_i18n'         => $nameI18n,
            'short_description' => $filter($category->getTranslations('lead') ?? []),
            'description'       => $filter($category->getTranslations('long_description') ?? []),
            'head_title'        => $filter($category->getTranslations('meta_title') ?? []),
            'meta_description'  => $filter($category->getTranslations('meta_description') ?? []),
            'meta_url'          => $filter($category->getTranslations('meta_url') ?? []),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  PRODUCTS
    // ═══════════════════════════════════════════════════════════════════════════

    public function syncProducts(?IntegrationSyncLog $log = null): void
    {
        $this->syncLog = $log;
        $this->taxMapping = $this->connector->getTaxMapping();

        $processed = 0;
        $total = IntegrationProduct::where('integration_id', $this->integration->id)->count();

        foreach ($this->integration->integrationSources as $source) {
            $this->currentSource = $source;
            app()->setLocale($source->template->locale ?? 'en');

            // Cena eksportowa: reczna (manual_price) gdy > 0, inaczej wlasciwa (price).
            $this->prices = PricelistProduct::exportPriceMap($source->pricelist_id);

            $this->productBuffer = [];

            IntegrationProduct::query()
                ->where('integration_id', $this->integration->id)
                ->where('integration_source_id', $source->id)
                ->with([
                    'product.media',
                    'product.categories',
                    'product.attributeValues.attribute.group',
                ])
                ->chunkById(50, function (Collection $chunk) use ($log, &$processed, $total) {
                    foreach ($chunk as $ip) {
                        /** @var IntegrationProduct $ip */
                        $product = $ip->getOverridedProduct();
                        if (!($product instanceof Product) || !$product->enabled) {
                            continue;
                        }

                        $this->productBuffer[] = $this->buildImportItem($product, $ip);
                        $processed++;

                        if ($log && $processed % 10 === 0) {
                            $log->tick($processed, $total, $product->product_code ?? null);
                        }
                    }

                    if (count($this->productBuffer) >= $this->batchSize) {
                        $this->flushProductBuffer();
                    }
                });

            $this->flushProductBuffer();
        }

        if ($log) {
            $log->tick($processed, $total);
        }
    }

    private function buildImportItem(Product $product, IntegrationProduct $ip): array
    {
        $locale   = $this->currentSource->template->locale ?? 'en';
        $template = $this->currentSource->template;

        // Template rendering
        $renderedTitle = $renderedDescription = $renderedShort = '';
        $renderedMetaTitle = $renderedMetaDesc = '';

        if ($template) {
            try { $renderedTitle       = trim((string) $template->getRenderedTitle($product)); } catch (\Throwable) {}
            try { $renderedDescription = trim((string) $template->getRenderedDescription($product)); } catch (\Throwable) {}
            try { $renderedShort       = trim((string) $template->getRenderedShortDescription($product)); } catch (\Throwable) {}
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

        // Krótki opis (info_2) — analogicznie do długiego: baza z tłumaczeń + nakładka z szablonu.
        $info2 = array_filter($product->getTranslations('info_2'), fn ($v) => trim(strip_tags((string) $v)) !== '');
        if ($renderedShort !== '') $info2[$locale] = $renderedShort;

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

        // Categories (mapped by ID)
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
        $vat        = (float) ($this->currentSource->vat ?? 0);
        $nettoPrice = $vat > 0
            ? round($price * $multiplier / (1 + $vat / 100), 6)
            : round($price * $multiplier, 6);

        $currency = strtoupper((string) optional($this->currentSource->pricelist)->currency ?: 'EUR');

        // Tax
        $taxRulesGroupId = null;
        if (!empty($this->currentSource->tax)) {
            $taxRulesGroupId = $this->taxMapping[(int) $this->currentSource->tax] ?? null;
        }

        // SKU
        $sku = trim((string) $product->product_code) ?: 'pim-' . $product->id;

        // Images — disabled until shop connectors support URL-based download
        // TODO: enable when pim-connector-presta.php handles source_url properly
        $images = []; // $this->buildImages($product);

        // Attributes (for LiteCart)
        $attributes = $this->buildAttributes($product);

        $item = [
            'pim_id'              => (int) $product->id,
            'sku'                 => $sku,
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
                'short_description' => $info2 ?: new \stdClass(),
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

        if (!empty($attributes)) {
            $item['attributes'] = $attributes;
        }

        return array_filter($item, fn ($v) => $v !== null);
    }

    /**
     * Build image payload using URLs — no base64!
     * Shop connector downloads images from PIM via source_url.
     */
    private function buildImages(Product $product): array
    {
        $images = [];

        foreach ($product->getMedia('images')->sortBy('order_column')->values() as $index => $media) {
            $filename = basename((string) $media->file_name) ?: "product-{$product->id}-{$index}.jpg";

            // Compute MD5 from local file if available, fallback to UUID
            $md5 = null;
            $localPath = $media->getPath();
            if (is_string($localPath) && is_file($localPath) && is_readable($localPath)) {
                $md5 = md5_file($localPath);
            }
            if (!$md5) {
                $md5 = md5((string) $media->uuid);
            }

            $images[] = [
                'pim_id'     => (int) $media->id,
                'filename'   => $filename,
                'priority'   => $index + 1,
                'source_url' => $media->getFullUrl(),
                'md5'        => $md5,
            ];
        }

        return $images;
    }

    /**
     * Build attributes array (used by LiteCart, ignored by PrestaShop connector).
     */
    private function buildAttributes(Product $product): array
    {
        $attrs = [];
        foreach ($product->attributeValues as $av) {
            $attribute = $av->attribute;
            if ($attribute === null) continue;

            $groupName = (string) optional($attribute->group)->name;
            if ($groupName === '') {
                $groupName = $this->firstTranslation(
                    (array) $attribute->getTranslations('name'),
                    (string) $attribute->name
                );
            }

            $valueName = $this->firstTranslation(
                (array) $av->getTranslations('name'),
                (string) $av->name
            );
            if ($valueName === '') continue;

            $attrs[] = [
                'group_name' => $groupName,
                'value_name' => $valueName,
            ];
        }
        return $attrs;
    }

    private function flushProductBuffer(): void
    {
        if (empty($this->productBuffer)) return;

        try {
            $results = $this->connector->importProducts($this->productBuffer);

            $syncedSkus = [];
            foreach ($results as $result) {
                if (($result['status'] ?? '') === 'ok') {
                    $syncedSkus[] = $result['data']['sku'] ?? null;
                } else {
                    $sku    = $result['data']['sku'] ?? ('index:' . ($result['index'] ?? '?'));
                    $errMsg = $result['message'] ?? 'unknown error';
                    Log::warning('SyncService: importProducts item error', [
                        'integration_id' => $this->integration->id,
                        'sku'            => $sku,
                        'message'        => $errMsg,
                    ]);
                    if ($this->syncLog) {
                        $this->syncLog->addError($sku, $errMsg);
                    }
                }
            }

            if (!empty($syncedSkus)) {
                $syncedSkus = array_filter($syncedSkus);
                IntegrationProduct::query()
                    ->where('integration_id', $this->integration->id)
                    ->whereHas('product', fn ($q) => $q->whereIn('product_code', $syncedSkus))
                    ->update(['synced_at' => now()]);
            }

        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            Log::error('SyncService: importProducts batch failed', [
                'integration_id' => $this->integration->id,
                'count'          => count($this->productBuffer),
                'message'        => $errMsg,
            ]);
            if ($this->syncLog) {
                foreach ($this->productBuffer as $item) {
                    $this->syncLog->addError($item['sku'] ?? '?', "Batch failed: {$errMsg}");
                }
            }
        }

        $this->productBuffer = [];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  BLOG
    // ═══════════════════════════════════════════════════════════════════════════

    public function syncBlog(): void
    {
        if (!$this->connector->supportsBlog()) {
            return;
        }

        $languageCodes = $this->connector->getLanguageCodes();

        foreach ($this->integration->integrationSources as $source) {
            if (!$source->blog_id) continue;

            $blog = Blog::find($source->blog_id);
            if (!$blog) continue;

            $locale = $source->template->locale ?? 'en';

            $this->syncBlogForSource($blog, $source, $locale, $languageCodes);
        }
    }

    private function syncBlogForSource(Blog $blog, IntegrationSource $source, string $locale, array $languageCodes): void
    {
        // 1. Authors
        $authors = $blog->authors()->where('enabled', true)->get()->map(fn ($a) => [
            'pim_id' => $a->id,
            'name'   => $a->name,
            'code'   => $a->slug,
            'bio'    => $a->bio ?? '',
            'status' => 1,
        ])->toArray();

        $this->connector->syncBlogAuthors($authors);

        // 2. Categories
        $categories = $blog->categories()->where('enabled', true)->orderBy('priority')->get()->map(
            fn ($cat) => [
                'pim_id'            => $cat->id,
                'status'            => 1,
                'priority'          => $cat->priority,
                'title_i18n'        => $this->fillMissingLocales($cat->getTranslations('name'), $languageCodes),
                'short_description' => $this->fillMissingLocales($cat->getTranslations('description'), $languageCodes),
                'head_title'        => $this->fillMissingLocales($cat->getTranslations('head_title'), $languageCodes),
                'meta_description'  => $this->fillMissingLocales($cat->getTranslations('meta_description'), $languageCodes),
                'meta_url'          => $this->fillMissingLocales($cat->getTranslations('meta_url'), $languageCodes),
            ]
        )->toArray();

        $categoryMap = $this->connector->syncBlogCategories($categories);

        // 3. Articles
        $articles = $blog->articles()
            ->with(['author', 'category', 'products'])
            ->where('status', '!=', 'disabled')
            ->get();

        $items = $articles->map(function (BlogArticle $article) use ($categoryMap, $locale, $languageCodes) {
            $lcCatId = $article->blog_category_id ? ($categoryMap[$article->blog_category_id] ?? null) : null;
            $lcStatus = $article->status === 'published' ? 1 : 0;

            $metaUrl = $this->fillMissingLocales(
                array_filter($article->getTranslations('meta_url'), fn ($v) => trim((string) $v) !== '')
                    ?: array_map(fn ($t) => Str::slug(strip_tags((string) $t)), $article->getTranslations('title')),
                $languageCodes
            );

            return array_filter([
                'meta_url'          => $metaUrl[$locale] ?? reset($metaUrl),
                'status'            => $lcStatus,
                'date_valid_from'   => $article->published_at?->format('Y-m-d'),
                'category_id'       => $lcCatId,
                'title_i18n'        => $this->fillMissingLocales($article->getTranslations('title'), $languageCodes),
                'short_description' => $this->fillMissingLocales($article->getTranslations('short_description'), $languageCodes),
                'content'           => $this->fillMissingLocales($article->getTranslations('content'), $languageCodes),
                'head_title'        => $this->fillMissingLocales($article->getTranslations('head_title'), $languageCodes),
                'meta_description'  => $this->fillMissingLocales($article->getTranslations('meta_description'), $languageCodes),
                'meta_keywords'     => $this->fillMissingLocales($article->getTranslations('meta_keywords'), $languageCodes),
                'author_name'       => $article->author?->name,
                'author_code'       => $article->author?->slug,
                'image'             => $article->image ? ['source_url' => $article->image] : null,
                'product_carousel'  => $article->products->map(fn ($p, $i) => [
                    'sku'        => (string) $p->product_code,
                    'product_id' => $p->id,
                    'priority'   => $p->pivot->priority ?? ($i + 1),
                ])->values()->toArray(),
            ], fn ($v) => $v !== null && $v !== [] && $v !== '');
        })->values()->toArray();

        try {
            $this->connector->syncBlogArticles($items);
        } catch (\Throwable $e) {
            Log::error('SyncService: blog articles import failed', [
                'integration_id' => $this->integration->id,
                'blog_id'        => $blog->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    private function fillMissingLocales(array $translations, array $requiredLocales = []): array
    {
        $fallback = '';
        foreach ($translations as $v) {
            if (trim((string) $v) !== '') {
                $fallback = (string) $v;
                break;
            }
        }
        if ($fallback === '' && empty($requiredLocales)) return $translations;

        foreach ($requiredLocales as $lang) {
            if (!isset($translations[$lang])) {
                $translations[$lang] = '';
            }
        }

        return array_map(fn ($v) => trim((string) $v) !== '' ? $v : $fallback, $translations);
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
