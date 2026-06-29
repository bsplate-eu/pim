<?php

namespace App\Services\Integration\Connectors;

use GuzzleHttp\RequestOptions;
use Throwable;

class LiteCartConnector extends AbstractHttpConnector
{
    protected function getConnectorFileConfigKey(): string
    {
        return 'litecart_connector_file';
    }

    protected function getUpdateConnectorRouteName(): string
    {
        return 'update-connector.litecart';
    }

    public function getRootCategoryId(): int
    {
        return 0;
    }

    public function getLanguageCodes(): array
    {
        try {
            $response = $this->request('getLanguages');
            $codes = [];
            foreach ($response['data'] ?? [] as $lang) {
                if (!empty($lang['code'])) {
                    $codes[] = (string) $lang['code'];
                }
            }
            return $codes ?: ['en'];
        } catch (Throwable) {
            return ['en'];
        }
    }

    // ── Blog support ─────────────────────────────────────────────────────────

    public function supportsBlog(): bool
    {
        return true;
    }

    public function syncBlogAuthors(array $authors): array
    {
        $map = [];
        foreach ($authors as $author) {
            try {
                $res = $this->blogRequest('POST', 'authors', $author);
                if (!empty($res['data']['id'])) {
                    $pimId = $author['pim_id'] ?? null;
                    if ($pimId) {
                        $map[$pimId] = (int) $res['data']['id'];
                    }
                }
            } catch (Throwable) {
                // individual author failure doesn't break sync
            }
        }
        return $map;
    }

    public function syncBlogCategories(array $categories): array
    {
        // Fetch existing categories from LiteCart
        $existingByTitle = [];
        try {
            $res = $this->blogRequest('GET', 'categories?limit=500');
            foreach ($res['data'] ?? [] as $lc) {
                $title = strtolower(trim((string) ($lc['title'] ?? '')));
                if ($title !== '') {
                    $existingByTitle[$title] = (int) $lc['id'];
                }
            }
        } catch (Throwable) {}

        $map = [];
        foreach ($categories as $cat) {
            try {
                $pimId = $cat['pim_id'] ?? null;
                unset($cat['pim_id']);

                // Try to find by title
                $lcId = null;
                foreach ($cat['title_i18n'] ?? [] as $val) {
                    $key = strtolower(trim((string) $val));
                    if ($key !== '' && isset($existingByTitle[$key])) {
                        $lcId = $existingByTitle[$key];
                        break;
                    }
                }

                if ($lcId) {
                    $this->blogRequest('PATCH', "categories/{$lcId}", $cat);
                } else {
                    $res = $this->blogRequest('POST', 'categories', $cat);
                    $lcId = (int) ($res['data']['id'] ?? 0);
                }

                if ($pimId && $lcId) {
                    $map[$pimId] = $lcId;
                }
            } catch (Throwable) {
                // individual category failure doesn't break sync
            }
        }
        return $map;
    }

    public function syncBlogArticles(array $articles): void
    {
        if (empty($articles)) {
            return;
        }
        $this->blogRequest('POST', 'articles/import', ['items' => $articles]);
    }

    // ── Blog REST API (LiteCart native /api/v1/blog/) ────────────────────────

    public function blogRequest(string $method, string $endpoint, array $payload = []): array
    {
        $base = rtrim((string) $this->integration->url, '/');
        $url  = $base . '/api/v1/blog/' . ltrim($endpoint, '/');

        $options = [
            RequestOptions::JSON    => $payload,
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer ' . $this->integration->key,
                'Accept'        => 'application/json',
            ],
            'timeout' => $this->getTimeout(),
        ];

        $response = $this->client->request($method, $url, $options);
        $body     = $response->getBody()->getContents();

        return json_decode($body, true, 512, JSON_UNESCAPED_UNICODE) ?? [];
    }

    // ── Tax mapping for LiteCart ─────────────────────────────────────────────

    public function getTaxMapping(): array
    {
        try {
            $response = $this->request('getTaxClasses');
            $taxes = [];
            foreach ($response['data']['tax_classes'] ?? [] as $tc) {
                $rate = (int) ($tc['rate'] ?? 0);
                if ($rate > 0) {
                    $taxes[$rate] = $tc['id_tax_rules_group'] ?? $tc['id'] ?? null;
                }
            }
            return $taxes;
        } catch (Throwable) {
            return [];
        }
    }
}
