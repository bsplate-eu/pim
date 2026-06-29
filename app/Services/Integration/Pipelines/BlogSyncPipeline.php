<?php

namespace App\Services\Integration\Pipelines;

use App\Models\Blog;
use App\Models\BlogArticle;
use App\Models\IntegrationBlogMapping;
use App\Services\Integration\Hashing\PayloadHasher;
use Illuminate\Support\Str;

class BlogSyncPipeline extends AbstractConnectorPipeline
{
    private array $languageCodes = [];

    public function getConnectorName(): string
    {
        return 'blog';
    }

    public function getQueueName(): string
    {
        return 'sync-blog';
    }

    public function shouldChainAfter(): array
    {
        return [];
    }

    protected function execute(): void
    {
        if (!$this->connector->supportsBlog()) {
            $this->logInfo('Connector does not support blog — skipping');
            return;
        }

        $this->languageCodes = $this->connector->getLanguageCodes();
        $this->integration->loadMissing('integrationSources.template');

        foreach ($this->integration->integrationSources as $source) {
            $this->assertNotInterrupted();
            if (!$source->blog_id) continue;

            $blog = Blog::find($source->blog_id);
            if (!$blog) continue;

            $locale = $source->template->locale ?? 'en';
            $this->syncBlogForSource($blog, $locale);
        }
    }

    private function syncBlogForSource(Blog $blog, string $locale): void
    {
        $this->syncAuthors($blog);
        $categoryMap = $this->syncCategories($blog);
        $this->syncArticles($blog, $categoryMap, $locale);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  AUTHORS
    // ═══════════════════════════════════════════════════════════════════════════

    private function syncAuthors(Blog $blog): void
    {
        $authors = $blog->authors()->where('enabled', true)->get();

        $payloads = $authors->map(fn ($a) => [
            'pim_id' => $a->id,
            'name'   => $a->name,
            'code'   => $a->slug,
            'bio'    => $a->bio ?? '',
            'status' => 1,
        ])->toArray();

        // Check which authors need syncing via hash
        $needsSync = [];
        foreach ($payloads as $payload) {
            $this->assertNotInterrupted();
            $hash = PayloadHasher::hashBlogPayload($payload);
            $mapping = IntegrationBlogMapping::query()
                ->where('integration_id', $this->integration->id)
                ->where('entity_type', IntegrationBlogMapping::ENTITY_AUTHOR)
                ->where('entity_id', $payload['pim_id'])
                ->first();

            if ($mapping && $mapping->payload_hash === $hash) {
                $this->run->incrementSkipped();
                continue;
            }

            $needsSync[] = ['payload' => $payload, 'hash' => $hash];
        }

        if (empty($needsSync)) return;

        try {
            $allPayloads = array_column($needsSync, 'payload');
            $resultMap = $this->connector->syncBlogAuthors($allPayloads);

            foreach ($needsSync as $item) {
                $this->assertNotInterrupted();
                $pimId     = $item['payload']['pim_id'];
                $remoteId  = $resultMap[$pimId] ?? null;

                if ($remoteId) {
                    IntegrationBlogMapping::updateOrCreate(
                        [
                            'integration_id' => $this->integration->id,
                            'entity_type'    => IntegrationBlogMapping::ENTITY_AUTHOR,
                            'entity_id'      => $pimId,
                        ],
                        [
                            'external_id'  => (string) $remoteId,
                            'payload_hash' => $item['hash'],
                            'synced_at'    => now(),
                        ]
                    );
                    $this->run->incrementUpdated();
                }
            }
        } catch (\Throwable $e) {
            $this->logError('syncBlogAuthors failed', ['error' => $e->getMessage()]);
            $this->run->addError('blog:authors', $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  CATEGORIES
    // ═══════════════════════════════════════════════════════════════════════════

    private function syncCategories(Blog $blog): array
    {
        $categories = $blog->categories()->where('enabled', true)->orderBy('priority')->get();

        $payloads = $categories->map(fn ($cat) => [
            'pim_id'            => $cat->id,
            'status'            => 1,
            'priority'          => $cat->priority,
            'title_i18n'        => $this->fillMissingLocales($cat->getTranslations('name')),
            'short_description' => $this->fillMissingLocales($cat->getTranslations('description')),
            'head_title'        => $this->fillMissingLocales($cat->getTranslations('head_title')),
            'meta_description'  => $this->fillMissingLocales($cat->getTranslations('meta_description')),
            'meta_url'          => $this->fillMissingLocales($cat->getTranslations('meta_url')),
        ])->toArray();

        try {
            $resultMap = $this->connector->syncBlogCategories($payloads);

            foreach ($payloads as $payload) {
                $this->assertNotInterrupted();
                $pimId    = $payload['pim_id'];
                $remoteId = $resultMap[$pimId] ?? null;
                $hash     = PayloadHasher::hashBlogPayload($payload);

                if ($remoteId) {
                    IntegrationBlogMapping::updateOrCreate(
                        [
                            'integration_id' => $this->integration->id,
                            'entity_type'    => IntegrationBlogMapping::ENTITY_CATEGORY,
                            'entity_id'      => $pimId,
                        ],
                        [
                            'external_id'  => (string) $remoteId,
                            'payload_hash' => $hash,
                            'synced_at'    => now(),
                        ]
                    );
                    $this->run->incrementUpdated();
                }
            }

            return $resultMap;
        } catch (\Throwable $e) {
            $this->logError('syncBlogCategories failed', ['error' => $e->getMessage()]);
            $this->run->addError('blog:categories', $e->getMessage());
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  ARTICLES
    // ═══════════════════════════════════════════════════════════════════════════

    private function syncArticles(Blog $blog, array $categoryMap, string $locale): void
    {
        $articles = $blog->articles()
            ->with(['author', 'category', 'products'])
            ->where('status', '!=', 'disabled')
            ->get();

        $items = $articles->map(function (BlogArticle $article) use ($categoryMap, $locale) {
            $lcCatId  = $article->blog_category_id ? ($categoryMap[$article->blog_category_id] ?? null) : null;
            $lcStatus = $article->status === 'published' ? 1 : 0;

            $metaUrl = $this->fillMissingLocales(
                array_filter($article->getTranslations('meta_url'), fn ($v) => trim((string) $v) !== '')
                    ?: array_map(fn ($t) => Str::slug(strip_tags((string) $t)), $article->getTranslations('title'))
            );

            return array_filter([
                'pim_id'            => $article->id,
                'meta_url'          => $metaUrl[$locale] ?? reset($metaUrl),
                'status'            => $lcStatus,
                'date_valid_from'   => $article->published_at?->format('Y-m-d'),
                'category_id'       => $lcCatId,
                'title_i18n'        => $this->fillMissingLocales($article->getTranslations('title')),
                'short_description' => $this->fillMissingLocales($article->getTranslations('short_description')),
                'content'           => $this->fillMissingLocales($article->getTranslations('content')),
                'head_title'        => $this->fillMissingLocales($article->getTranslations('head_title')),
                'meta_description'  => $this->fillMissingLocales($article->getTranslations('meta_description')),
                'meta_keywords'     => $this->fillMissingLocales($article->getTranslations('meta_keywords')),
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

            // Save mappings with hashes
            foreach ($items as $item) {
                $this->assertNotInterrupted();
                $hash = PayloadHasher::hashBlogPayload($item);
                IntegrationBlogMapping::updateOrCreate(
                    [
                        'integration_id' => $this->integration->id,
                        'entity_type'    => IntegrationBlogMapping::ENTITY_ARTICLE,
                        'entity_id'      => $item['pim_id'],
                    ],
                    [
                        'external_id'  => (string) ($item['pim_id']),
                        'payload_hash' => $hash,
                        'synced_at'    => now(),
                    ]
                );
                $this->run->incrementUpdated();
            }
        } catch (\Throwable $e) {
            $this->logError('syncBlogArticles failed', ['error' => $e->getMessage()]);
            $this->run->addError('blog:articles', $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    private function fillMissingLocales(array $translations): array
    {
        $fallback = '';
        foreach ($translations as $v) {
            if (trim((string) $v) !== '') {
                $fallback = (string) $v;
                break;
            }
        }

        foreach ($this->languageCodes as $lang) {
            if (!isset($translations[$lang])) {
                $translations[$lang] = '';
            }
        }

        return array_map(fn ($v) => trim((string) $v) !== '' ? $v : $fallback, $translations);
    }
}
