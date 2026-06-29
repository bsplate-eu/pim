<?php

namespace App\Services\Integration\Hashing;

class PayloadHasher
{
    /**
     * Hash a product import payload for delta detection.
     * Covers: name, sku, ean, status, prices, categories, SEO, tax, weight, width, manufacturer.
     */
    public static function hashProductPayload(array $item): string
    {
        $normalized = [
            'sku'                 => $item['sku'] ?? '',
            'ean'                 => $item['ean'] ?? '',
            'status'              => (int) ($item['status'] ?? 0),
            'name_i18n'           => self::sortAssoc($item['name_i18n'] ?? []),
            'quantity'            => (int) ($item['quantity'] ?? 0),
            'prices'              => self::sortAssoc($item['prices'] ?? []),
            'id_tax_rules_group'  => $item['id_tax_rules_group'] ?? null,
            'available_for_order' => (int) ($item['available_for_order'] ?? 0),
            'show_price'          => (int) ($item['show_price'] ?? 0),
            'weight'              => $item['weight'] ?? null,
            'width'               => $item['width'] ?? null,
            'categories'          => self::sortedIntArray($item['categories'] ?? []),
            'manufacturer_name'   => $item['manufacturer_name'] ?? '',
            'seo'                 => self::normalizeSeo($item['seo'] ?? []),
            'attributes'          => $item['attributes'] ?? [],
        ];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Hash a category payload for delta detection.
     */
    public static function hashCategoryPayload(array $payload): string
    {
        $normalized = [
            'name_i18n'         => self::sortAssoc($payload['name_i18n'] ?? []),
            'short_description' => self::sortAssoc($payload['short_description'] ?? []),
            'description'       => self::sortAssoc($payload['description'] ?? []),
            'head_title'        => self::sortAssoc($payload['head_title'] ?? []),
            'meta_description'  => self::sortAssoc($payload['meta_description'] ?? []),
            'meta_url'          => self::sortAssoc($payload['meta_url'] ?? []),
        ];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Hash a blog entity payload (author, category, or article).
     */
    public static function hashBlogPayload(array $payload): string
    {
        $normalized = self::deepSort($payload);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    private static function sortAssoc(array|object $data): array
    {
        $arr = (array) $data;
        ksort($arr);
        return $arr;
    }

    private static function sortedIntArray(array $data): array
    {
        $ints = array_map('intval', array_values(array_unique($data)));
        sort($ints);
        return $ints;
    }

    private static function normalizeSeo(array $seo): array
    {
        $normalized = [];
        foreach (['short_description', 'description', 'head_title', 'meta_description', 'meta_keywords', 'meta_url'] as $key) {
            $value = $seo[$key] ?? null;
            $normalized[$key] = is_array($value) ? self::sortAssoc($value) : $value;
        }
        return $normalized;
    }

    private static function deepSort(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::deepSort($value);
            }
        }
        return $data;
    }
}
