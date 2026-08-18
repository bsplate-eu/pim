<?php

namespace App\Services\Ebay;

use App\Models\Scrap\EbaySettings;
use GuzzleHttp\Client;

/**
 * Klient eBay Sell API (REST/JSON) — WYSTAWIANIE ofert: Inventory API + Account API.
 *
 * To osobny świat od EbaySellClient (Trading, XML), który obsługuje NASZE ISTNIEJĄCE ~3338 aukcji.
 * Świadoma hybryda (patrz docs/ebay-wystawianie/01-wzorzec-allegro-oms.md §8):
 *  • stare aukcje  → Trading (ilość, cena, ktype-push) — bez zmian,
 *  • nowe oferty   → Inventory (szkic → weryfikacja → aktywacja), bo tylko ten model zna „szkic".
 *
 * Cykl: createOrReplaceInventoryItem (SKU: dane produktu + aspekty)
 *     → createOffer (rynek, cena, ilość, kategoria, polityki) → publishOffer → listingId.
 *
 * Autoryzacja: user access-token z EbayOAuthService (Bearer) — ten sam, co Trading.
 */
class EbayInventoryClient
{
    private Client $http;
    private string $api = 'https://api.ebay.com';

    /** Rynek → język treści oferty (nagłówek Content-Language wymagany przy zapisie). */
    private const CONTENT_LANGUAGE = [
        'EBAY_DE' => 'de-DE', 'EBAY_AT' => 'de-AT', 'EBAY_FR' => 'fr-FR',
        'EBAY_ES' => 'es-ES', 'EBAY_IT' => 'it-IT', 'EBAY_PL' => 'pl-PL',
        'EBAY_GB' => 'en-GB', 'EBAY_US' => 'en-US',
    ];

    public function __construct(
        private EbaySettings $settings,
        private EbayOAuthService $oauth,
    ) {
        $this->http = new Client(['timeout' => 60, 'http_errors' => false]);
    }

    public static function fromSettings(EbaySettings $settings): self
    {
        return new self($settings, new EbayOAuthService($settings));
    }

    private function contentLanguage(string $marketplace): string
    {
        return self::CONTENT_LANGUAGE[strtoupper($marketplace)] ?? 'en-US';
    }

    /**
     * Wywołanie REST. Zwraca zdekodowane ciało (puste [] dla 204).
     * Rzuca RuntimeException z komunikatem eBaya — surowy JSON błędu jest nieczytelny w UI.
     */
    private function call(string $method, string $path, array $options = [], ?string $marketplace = null): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->oauth->accessToken(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($marketplace) {
            $headers['X-EBAY-C-MARKETPLACE-ID'] = strtoupper($marketplace);
            $headers['Content-Language'] = $this->contentLanguage($marketplace);
        }

        $res = $this->http->request($method, $this->api.$path, $options + ['headers' => $headers]);
        $status = $res->getStatusCode();
        $raw = (string) $res->getBody();
        $body = $raw === '' ? [] : (json_decode($raw, true) ?? []);

        if ($status >= 400) {
            throw new \RuntimeException($this->humanize($body, $status));
        }

        return is_array($body) ? $body : [];
    }

    /**
     * Komunikaty eBaya w formie do pokazania człowiekowi.
     * eBay zwraca `errors[]` z `message` i często `parameters[]` — bez nich „Invalid value"
     * nie mówi nic o tym, KTÓRE pole jest złe.
     */
    private function humanize(array $body, int $status): string
    {
        $parts = [];

        foreach (array_merge($body['errors'] ?? [], $body['warnings'] ?? []) as $e) {
            $msg = $e['longMessage'] ?? $e['message'] ?? null;
            if (! $msg) {
                continue;
            }
            $params = collect($e['parameters'] ?? [])
                ->map(fn ($p) => ($p['name'] ?? '?').'='.($p['value'] ?? '?'))
                ->implode(', ');

            $parts[] = $params !== '' ? "{$msg} ({$params})" : $msg;
        }

        return $parts !== []
            ? implode(' | ', array_unique($parts))
            : "eBay HTTP {$status}: ".mb_substr(json_encode($body, JSON_UNESCAPED_UNICODE) ?: '', 0, 300);
    }

    // ─── Inventory: pozycja magazynowa (SKU) ──────────────────────────────

    /**
     * Utwórz/nadpisz pozycję magazynową pod danym SKU (PUT — idempotentne).
     * $aspects = ['Hersteller' => ['BSP'], …] — eBay oczekuje TABLICY wartości nawet dla SINGLE.
     */
    public function createOrReplaceInventoryItem(
        string $sku,
        string $marketplace,
        string $title,
        string $description,
        array $aspects,
        array $imageUrls,
        int $quantity,
    ): void {
        $payload = [
            'availability' => ['shipToLocationAvailability' => ['quantity' => max(0, $quantity)]],
            'condition' => 'NEW',
            'product' => array_filter([
                'title' => $title,
                'description' => $description,
                'aspects' => $aspects ?: null,
                'imageUrls' => $imageUrls ?: null,
            ]),
        ];

        $this->call('PUT', '/sell/inventory/v1/inventory_item/'.rawurlencode($sku), ['json' => $payload], $marketplace);
    }

    /** Skasuj pozycję magazynową (sprzątanie po nieudanym wystawieniu). */
    public function deleteInventoryItem(string $sku, string $marketplace): void
    {
        $this->call('DELETE', '/sell/inventory/v1/inventory_item/'.rawurlencode($sku), [], $marketplace);
    }

    // ─── Inventory: oferta ────────────────────────────────────────────────

    /** Utwórz ofertę (jeszcze NIEopublikowaną — to jest właśnie „szkic"). Zwraca offerId. */
    public function createOffer(array $payload, string $marketplace): string
    {
        $res = $this->call('POST', '/sell/inventory/v1/offer', ['json' => $payload], $marketplace);

        return (string) ($res['offerId'] ?? '');
    }

    /** Oferty istniejące dla SKU na danym rynku — zabezpieczenie przed dublem. */
    public function offersForSku(string $sku, string $marketplace): array
    {
        $res = $this->call('GET', '/sell/inventory/v1/offer?'.http_build_query([
            'sku' => $sku,
            'marketplace_id' => strtoupper($marketplace),
        ]), [], $marketplace);

        return $res['offers'] ?? [];
    }

    /** Opublikuj ofertę = aukcja idzie na żywo. Zwraca listingId (ItemID). */
    public function publishOffer(string $offerId, string $marketplace): string
    {
        $res = $this->call('POST', "/sell/inventory/v1/offer/{$offerId}/publish", [], $marketplace);

        return (string) ($res['listingId'] ?? '');
    }

    /** Wycofaj opublikowaną ofertę (odpowiednik „zakończ aukcję"). */
    public function withdrawOffer(string $offerId, string $marketplace): void
    {
        $this->call('POST', "/sell/inventory/v1/offer/{$offerId}/withdraw", [], $marketplace);
    }

    // ─── Account: polityki i lokalizacje ──────────────────────────────────

    /**
     * Polityki biznesowe konta dla rynku: dostawa / płatność / zwroty.
     * Bez nich eBay nie POZWOLI opublikować oferty (szkic powstanie).
     *
     * @return array{fulfillment:list<array>, payment:list<array>, return:list<array>}
     */
    public function businessPolicies(string $marketplace): array
    {
        $mp = strtoupper($marketplace);
        $fetch = function (string $path, string $key) use ($mp): array {
            try {
                $res = $this->call('GET', "/sell/account/v1/{$path}?marketplace_id={$mp}");

                return collect($res[$key] ?? [])->map(fn ($p) => [
                    'id' => (string) ($p[rtrim($key, 's').'Id'] ?? $p['fulfillmentPolicyId'] ?? $p['paymentPolicyId'] ?? $p['returnPolicyId'] ?? ''),
                    'name' => (string) ($p['name'] ?? ''),
                ])->filter(fn ($p) => $p['id'] !== '')->values()->all();
            } catch (\Throwable) {
                // Brak polityk to nie błąd — konto może ich po prostu nie mieć skonfigurowanych.
                return [];
            }
        };

        return [
            'fulfillment' => $fetch('fulfillment_policy', 'fulfillmentPolicies'),
            'payment' => $fetch('payment_policy', 'paymentPolicies'),
            'return' => $fetch('return_policy', 'returnPolicies'),
        ];
    }

    /** Lokalizacje magazynowe konta (merchantLocationKey wymagany w ofercie). */
    public function inventoryLocations(): array
    {
        try {
            $res = $this->call('GET', '/sell/inventory/v1/location?limit=100');

            return collect($res['locations'] ?? [])->map(fn ($l) => [
                'key' => (string) ($l['merchantLocationKey'] ?? ''),
                'name' => (string) ($l['name'] ?? $l['merchantLocationKey'] ?? ''),
            ])->filter(fn ($l) => $l['key'] !== '')->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
