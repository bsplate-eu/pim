<?php

namespace App\Services\Integration\Connectors;

use App\Contracts\ShopConnectorInterface;
use App\Models\Integration;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Str;
use Throwable;

abstract class AbstractHttpConnector implements ShopConnectorInterface
{
    protected Integration $integration;
    protected Client $client;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;

        $config = [
            'verify'          => (bool) config('integrations.verify_tls', true),
            'http_errors'     => false,
            'allow_redirects' => false,
            'timeout'         => $this->getTimeout(),
        ];

        // Wymuszenie IPv4/IPv6. Domyślnie IPv4: na tym hostingu domena sklepu ma AAAA
        // wskazujący na serwer, gdzie po IPv6 stoi tylko domyślny vhost (zły cert ->
        // cURL 60, connector 404); poprawny vhost+cert sklepu jest na IPv4.
        $ipVersion = (string) config('integrations.connector_ip_version', 'v4');
        if (in_array($ipVersion, ['v4', 'v6'], true)) {
            $config['force_ip_resolve'] = $ipVersion;
        }

        $this->client = new Client($config);
    }

    // ── Abstract methods for platform-specific config ────────────────────────

    abstract protected function getConnectorFileConfigKey(): string;

    abstract protected function getUpdateConnectorRouteName(): string;

    abstract public function getRootCategoryId(): int;

    // ── Configurable defaults ────────────────────────────────────────────────

    protected function getTimeout(): int
    {
        return (int) config('integrations.connector_timeout', 120);
    }

    // ── ShopConnectorInterface: Connection ───────────────────────────────────

    public function checkConnection(): bool
    {
        try {
            $this->request('checkConnected');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    // ── ShopConnectorInterface: Categories ───────────────────────────────────

    public function getCategories(): array
    {
        $response = $this->request('getCategoryTree');
        return $response['data'] ?? [];
    }

    public function createCategory(int $parentId, array $payload): int
    {
        $response = $this->request('addCategory', array_merge($payload, [
            'category_parent_id' => $parentId,
        ]));
        return (int) ($response['data']['category_id'] ?? 0);
    }

    public function updateCategory(int $categoryId, array $payload): void
    {
        $this->request('updateCategory', array_merge($payload, [
            'category_id' => $categoryId,
        ]));
    }

    // ── ShopConnectorInterface: Products ─────────────────────────────────────

    public function importProducts(array $items): array
    {
        $response = $this->request('importProducts', ['items' => $items]);
        return $response['data'] ?? [];
    }

    // ── ShopConnectorInterface: Blog (default: not supported) ────────────────

    public function supportsBlog(): bool
    {
        return false;
    }

    public function syncBlogAuthors(array $authors): array
    {
        return [];
    }

    public function syncBlogCategories(array $categories): array
    {
        return [];
    }

    public function syncBlogArticles(array $articles): void
    {
    }

    // ── ShopConnectorInterface: Meta ─────────────────────────────────────────

    public function getLanguageCodes(): array
    {
        try {
            $response = $this->request('checkConnected');
            return [$response['data']['store_lang'] ?? 'en'];
        } catch (Throwable) {
            return ['en'];
        }
    }

    public function getTaxMapping(): array
    {
        try {
            $response = $this->request('getTaxes');
            $taxes = [];
            foreach ($response['data']['taxes'] ?? [] as $tax) {
                $rate = (int) ($tax['rate'] ?? 0);
                if ($rate > 0) {
                    $taxes[$rate] = $tax['id_tax_rules_group'] ?? null;
                }
            }
            return $taxes;
        } catch (Throwable) {
            return [];
        }
    }

    public function getAnalytics(string $dateFrom, string $dateTo): array
    {
        try {
            $response = $this->request('getAnalytics', [
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
            ]);
            return $response['data'] ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    // ── Core HTTP transport ──────────────────────────────────────────────────

    /**
     * Circuit breaker / retry config (2026-05-16):
     *  - max 3 proby
     *  - exponential backoff: 2s, 4s (przed 2. i 3. proba)
     *  - retry tylko na network errors / timeouty / 5xx
     *  - bez retry na: business logic error (status: error w response), 4xx, invalid JSON
     */
    private const RETRY_MAX_ATTEMPTS = 3;
    private const RETRY_INITIAL_DELAY_MS = 2000;

    protected function request(string $method, array $parameters = []): array
    {
        $attempt = 0;
        $delay = self::RETRY_INITIAL_DELAY_MS;
        $lastError = null;

        while ($attempt < self::RETRY_MAX_ATTEMPTS) {
            $attempt++;
            try {
                return $this->performRequest($method, $parameters);
            } catch (\Throwable $e) {
                $lastError = $e;

                // Nie retry: blad logiki biznesowej (status:error w odpowiedzi sklepu)
                // ani invalid JSON (sklep odpowiedzial, ale niezgodnie z kontraktem)
                $msg = $e->getMessage();
                $shouldRetry = $this->isTransientError($msg);

                if (!$shouldRetry || $attempt >= self::RETRY_MAX_ATTEMPTS) {
                    break;
                }

                usleep($delay * 1000); // ms -> us
                $delay *= 2;
            }
        }

        throw new \Exception(
            sprintf('Connector failed after %d attempts: %s', $attempt, $lastError?->getMessage()),
            (int) ($lastError?->getCode() ?? 0),
            $lastError
        );
    }

    /**
     * Klasyfikacja bledu: transient (retry) vs permanent (throw).
     * Retry: timeout, connection refused, DNS, 5xx, gateway timeout.
     * No retry: 4xx, business logic error, invalid JSON.
     */
    private function isTransientError(string $message): bool
    {
        // Business logic / contract errors - nie retry
        if (str_contains($message, 'Connector returned invalid JSON')) {
            return false;
        }
        if (preg_match('/HTTP 4\d\d/', $message)) {
            return false;
        }
        // 5xx, network, timeout - retry
        $transientPatterns = [
            'cURL error 6',   // DNS lookup failed
            'cURL error 7',   // Connection refused
            'cURL error 28',  // Operation timeout
            'cURL error 35',  // SSL connect error
            'cURL error 52',  // Empty reply from server
            'HTTP 502',
            'HTTP 503',
            'HTTP 504',
            'timeout',
            'Timeout',
        ];
        foreach ($transientPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }
        // Domyslnie nie retry zeby uniknac amplifikacji bledow
        return false;
    }

    private function performRequest(string $method, array $parameters = []): array
    {
        $parameters['api_key'] = (string) $this->integration->key;
        $parameters['method']  = $method;

        // Connector file + checksum + auto-update URL
        $connectorFile = $this->getConnectorFileName();
        $connectorPath = storage_path('app/' . $connectorFile);

        if (is_file($connectorPath)) {
            $parameters['checksum_connector'] = md5_file($connectorPath) ?: '';
            $parameters['url_connector']      = rtrim((string) config('app.url'), '/') . '/' . str_replace('.', '/', $this->getUpdateConnectorRouteName());
        }

        // Build URL
        $url = rtrim((string) $this->integration->url, '/') . '/' . $connectorFile;

        try {
            $response = $this->client->post($url, [
                RequestOptions::JSON => $parameters,
                'timeout'            => $this->getTimeout(),
            ]);

            $contents = $response->getBody()->getContents();
            $data     = json_decode($contents, true, 512, JSON_UNESCAPED_UNICODE);

            // Extract JSON from dirty response (e.g. LiteSpeed Cache PHP notices)
            if (!is_array($data) && preg_match('/\{[^{}]*"status"\s*:/', $contents)) {
                $jsonStart = strpos($contents, '{');
                if ($jsonStart !== false) {
                    $data = json_decode(substr($contents, $jsonStart), true, 512, JSON_UNESCAPED_UNICODE);
                }
            }

            if (!is_array($data)) {
                $statusCode = $response->getStatusCode();
                $snippet = trim($contents) === ''
                    ? '(empty response)'
                    : substr(preg_replace('/\s+/', ' ', $contents), 0, 500);
                throw new \Exception(
                    "Connector returned invalid JSON (HTTP {$statusCode}). URL: {$url}. Response: {$snippet}"
                );
            }

            if (isset($data['status']) && $data['status'] === 'error') {
                $code = $data['code'] ?? null;
                $suffix = $code !== null && $code !== '' ? " (code: {$code})" : '';
                throw new \Exception(($data['message'] ?? 'Connector error') . $suffix);
            }

            return $data;

        } catch (Throwable $e) {
            throw new \Exception($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    protected function getConnectorFileName(): string
    {
        $file = trim((string) config('integrations.' . $this->getConnectorFileConfigKey()));
        if ($file === '') {
            throw new \Exception(
                "Config integrations.{$this->getConnectorFileConfigKey()} is empty. "
                . "Set it in .env or config/integrations.php."
            );
        }
        return $file;
    }
}
