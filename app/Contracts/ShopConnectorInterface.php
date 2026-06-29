<?php

namespace App\Contracts;

interface ShopConnectorInterface
{
    /**
     * Test connection to the shop.
     */
    public function checkConnection(): bool;

    // ── Categories ───────────────────────────────────────────────────────────

    /**
     * Fetch flat list of remote categories.
     * Each item: ['id_category' => int, 'parent_id' => int, 'name' => string]
     */
    public function getCategories(): array;

    /**
     * Create a category under $parentId. Returns remote category ID.
     */
    public function createCategory(int $parentId, array $payload): int;

    /**
     * Update an existing category by remote ID.
     */
    public function updateCategory(int $categoryId, array $payload): void;

    // ── Products ─────────────────────────────────────────────────────────────

    /**
     * Batch upsert products by SKU.
     * Returns per-item results: [['status' => 'ok'|'error', 'data' => [...], 'message' => '...']]
     */
    public function importProducts(array $items): array;

    // ── Blog ─────────────────────────────────────────────────────────────────

    /**
     * Does this shop support blog sync?
     */
    public function supportsBlog(): bool;

    /**
     * Sync blog authors. Returns array of pim_id => remote_id mappings.
     */
    public function syncBlogAuthors(array $authors): array;

    /**
     * Sync blog categories. Returns array of pim_id => remote_id mappings.
     */
    public function syncBlogCategories(array $categories): array;

    /**
     * Sync blog articles.
     */
    public function syncBlogArticles(array $articles): void;

    // ── Meta ─────────────────────────────────────────────────────────────────

    /**
     * Root category ID in the shop (e.g., 2 for PrestaShop, 0 for LiteCart).
     */
    public function getRootCategoryId(): int;

    /**
     * Active language codes in the shop, e.g., ['en', 'pl'].
     */
    public function getLanguageCodes(): array;

    /**
     * Tax mapping: rate => tax_rules_group_id (or equivalent).
     */
    public function getTaxMapping(): array;

    // ── Analytics ────────────────────────────────────────────────────────────

    /**
     * Fetch daily page view analytics from the shop.
     * Returns array of: ['entity_type' => 'product'|'category', 'external_id' => int, 'date' => 'Y-m-d', 'page_views' => int, 'unique_views' => int]
     */
    public function getAnalytics(string $dateFrom, string $dateTo): array;
}
