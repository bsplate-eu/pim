<?php

namespace App\Services\Ebay;

use App\Models\Scrap\EbaySettings;
use GuzzleHttp\Client;

/**
 * Klient eBay Trading API (XML) dla WŁASNYCH ofert — uwierzytelnianie OAuth user-token
 * przez nagłówek X-EBAY-API-IAF-TOKEN (IAF = Identity Assertion Framework).
 *
 * GetSellerList + IncludeVariations: wszystkie aktywne aukcje sprzedawcy z wariantami (SKU + cena).
 * ReviseInventoryStatus: zmiana ceny/ilości (do 4 pozycji na wywołanie) — Etap „Operacje".
 *
 * UWAGA: parsing wg dokumentacji eBay — do weryfikacji przy pierwszym realnym pobraniu (po OAuth/Sandbox).
 */
class EbaySellClient
{
    private Client $http;
    private string $api = 'https://api.ebay.com/ws/api.dll';
    private int $compatLevel = 1193;

    /** marketplace → Trading SiteID. */
    private const SITE_IDS = [
        'EBAY_US' => 0, 'EBAY_GB' => 3, 'EBAY_AT' => 16, 'EBAY_FR' => 71,
        'EBAY_DE' => 77, 'EBAY_IT' => 101, 'EBAY_ES' => 186, 'EBAY_PL' => 212,
    ];

    public function __construct(
        private EbaySettings $settings,
        private EbayOAuthService $oauth,
    ) {
        $this->http = new Client(['timeout' => 60, 'http_errors' => false]);
    }

    private function siteId(?string $marketplace): int
    {
        return self::SITE_IDS[strtoupper((string) $marketplace)] ?? 77;
    }

    /** Wywołanie Trading API: zwraca sparsowany (i odnamespace'owany) SimpleXML albo rzuca z błędem eBay. */
    private function call(string $callName, string $bodyXml, string $marketplace): \SimpleXMLElement
    {
        $res = $this->http->post($this->api, [
            'headers' => [
                'X-EBAY-API-CALL-NAME' => $callName,
                'X-EBAY-API-SITEID' => $this->siteId($marketplace),
                'X-EBAY-API-COMPATIBILITY-LEVEL' => $this->compatLevel,
                'X-EBAY-API-IAF-TOKEN' => $this->oauth->accessToken(),
                'Content-Type' => 'text/xml',
            ],
            'body' => $bodyXml,
        ]);

        $raw = (string) $res->getBody();
        // Usuń domyślny namespace, by dało się czytać prosto ($xml->ItemArray->Item).
        $xml = simplexml_load_string(preg_replace('/ xmlns="[^"]*"/', '', $raw, 1) ?: $raw);
        if ($xml === false) {
            throw new \RuntimeException("{$callName}: nieprawidłowa odpowiedź XML (HTTP {$res->getStatusCode()}).");
        }
        if ((string) $xml->Ack === 'Failure') {
            $msg = (string) ($xml->Errors->LongMessage ?? $xml->Errors->ShortMessage ?? 'nieznany błąd eBay');
            throw new \RuntimeException("{$callName}: {$msg}");
        }

        return $xml;
    }

    /** Jedna strona aktywnych ofert. Zwraca ['items'=>array<row>, 'total_pages'=>int]. */
    public function activeListingsPage(string $marketplace, int $page = 1, int $perPage = 100): array
    {
        $from = gmdate('Y-m-d\TH:i:s.000\Z');
        $to = gmdate('Y-m-d\TH:i:s.000\Z', time() + 119 * 86400); // okno EndTime ≤ 120 dni = aktywne

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<GetSellerListRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
            . "<EndTimeFrom>{$from}</EndTimeFrom>"
            . "<EndTimeTo>{$to}</EndTimeTo>"
            . '<IncludeVariations>true</IncludeVariations>'
            . '<GranularityLevel>Fine</GranularityLevel>'
            . '<Pagination>'
            . "<EntriesPerPage>{$perPage}</EntriesPerPage>"
            . "<PageNumber>{$page}</PageNumber>"
            . '</Pagination>'
            . '</GetSellerListRequest>';

        $xml = $this->call('GetSellerList', $body, $marketplace);

        return [
            'items' => $this->parseItems($xml, $marketplace),
            'total_pages' => (int) ($xml->PaginationResult->TotalNumberOfPages ?? 1),
        ];
    }

    /** Spłaszczenie ItemArray → wiersze (po wariancie; oferta bez wariantów = jeden wiersz). */
    private function parseItems(\SimpleXMLElement $xml, string $marketplace): array
    {
        $rows = [];

        foreach ($xml->ItemArray->Item ?? [] as $item) {
            $itemId = (string) $item->ItemID;
            $title = (string) $item->Title;
            $url = (string) ($item->ListingDetails->ViewItemURL ?? '');
            $status = (string) ($item->SellingStatus->ListingStatus ?? 'Active');
            $currency = (string) ($item->SellingStatus->CurrentPrice['currencyID'] ?? $item->Currency ?? 'EUR');

            $variations = $item->Variations->Variation ?? null;

            if ($variations !== null && count($variations) > 0) {
                foreach ($variations as $v) {
                    $specifics = [];
                    foreach ($v->VariationSpecifics->NameValueList ?? [] as $nv) {
                        $specifics[(string) $nv->Name] = (string) $nv->Value;
                    }
                    $rows[] = [
                        'item_id' => $itemId,
                        'sku' => (string) ($v->SKU ?? ''),
                        'marketplace' => $marketplace,
                        'title' => $title,
                        'price' => (float) ($v->StartPrice ?? 0),
                        'currency' => (string) ($v->StartPrice['currencyID'] ?? $currency),
                        'quantity' => (int) ($v->Quantity ?? 0),
                        'listing_status' => $status,
                        'listing_url' => $url,
                        'variation' => $specifics ?: null,
                    ];
                }
            } else {
                $rows[] = [
                    'item_id' => $itemId,
                    'sku' => (string) ($item->SKU ?? ''),
                    'marketplace' => $marketplace,
                    'title' => $title,
                    'price' => (float) ($item->SellingStatus->CurrentPrice ?? $item->StartPrice ?? 0),
                    'currency' => $currency,
                    'quantity' => (int) ($item->QuantityAvailable ?? $item->Quantity ?? 0),
                    'listing_status' => $status,
                    'listing_url' => $url,
                    'variation' => null,
                ];
            }
        }

        return $rows;
    }

    /** Zmiana ceny pojedynczej pozycji (ReviseInventoryStatus). $sku puste → cała oferta (bez wariantów). */
    public function revisePrice(string $itemId, string $sku, float $price, string $marketplace): void
    {
        $skuXml = $sku !== '' ? "<SKU>{$sku}</SKU>" : '';
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<ReviseInventoryStatusRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
            . '<InventoryStatus>'
            . "<ItemID>{$itemId}</ItemID>"
            . $skuXml
            . '<StartPrice>' . number_format($price, 2, '.', '') . '</StartPrice>'
            . '</InventoryStatus>'
            . '</ReviseInventoryStatusRequest>';

        $this->call('ReviseInventoryStatus', $body, $marketplace);
    }
}
