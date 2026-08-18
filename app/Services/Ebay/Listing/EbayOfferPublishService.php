<?php

namespace App\Services\Ebay\Listing;

use App\Models\Ebay\EbayOffer;
use App\Models\Ebay\EbayScheme;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayInventoryClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Etap E — FAKTYCZNE wystawianie ofert na eBay wg schematu (Inventory API).
 * Wzorzec: App\Services\Allegro\Listing\AllegroOfferPublishService z OMS ARGO.
 *
 * Cykl per produkt: inventory_item (SKU) → offer (szkic) → opcjonalnie publish (aktywacja).
 *
 * BEZPIECZNIK: oferta powstaje jako SZKIC. `publishOffer()` (czyli wyjście na żywo) leci tylko
 * gdy schemat ma `publication_mode = active`. Produkt z istniejącą ofertą na tym rynku jest
 * pomijany — automat nie powiela aukcji.
 *
 * Zwraca trzy kubełki (jak OMS): published / failed / skipped — każdy z powodem. To różnica
 * między „poszło 40 z 50" a „poszło 40, 7 bez ceny, 3 już były".
 */
class EbayOfferPublishService
{
    /** Twardy limit pozycji na jedno wywołanie — publikacja idzie w cyklu requestu, nie w kolejce. */
    public const MAX_BATCH = 50;

    public function __construct(
        private readonly EbayOfferDraftBuilder $builder,
    ) {}

    /**
     * @param  Collection<int,Product>  $products  z załadowanymi attributeValues.attribute + media
     * @return array{published:list<array>, failed:list<array>, skipped:list<array>}
     */
    public function publish(EbayScheme $scheme, Collection $products): array
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            throw new \RuntimeException('Konto eBay nie jest połączone — wystawianie wymaga OAuth (Integracje → Ebay).');
        }

        if (($problems = $scheme->problems()) !== []) {
            throw new \RuntimeException('Schemat niekompletny: '.implode(' · ', $problems).'.');
        }

        // Aktywacja (wyjście na żywo) wymaga polityk i lokalizacji; szkic przejdzie bez nich.
        if ($scheme->publishesActive() && ($missing = $this->missingForActivation($scheme)) !== []) {
            throw new \RuntimeException(
                'Schemat ma tryb „od razu aktywna", ale brakuje: '.implode(', ', $missing)
                .'. Uzupełnij je w schemacie albo przełącz na szkic.'
            );
        }

        $marketplace = strtoupper($scheme->marketplace);
        $client = EbayInventoryClient::fromSettings($settings);

        $prices = PricelistProduct::where('pricelist_id', $scheme->pricelist_id)
            ->whereIn('product_id', $products->pluck('id'))
            ->pluck('price', 'product_id');

        // Automat nie powiela ofert: pomiń produkty, które mają już aukcję na tym rynku.
        $alreadyListed = EbayOffer::where('marketplace', $marketplace)
            ->whereIn('product_id', $products->pluck('id'))
            ->pluck('product_id')
            ->flip();

        $published = [];
        $failed = [];
        $skipped = [];

        foreach ($products as $product) {
            $label = ['product_id' => $product->id, 'product_code' => $product->product_code];

            if ($alreadyListed->has($product->id)) {
                $skipped[] = $label + ['reason' => 'Produkt ma już ofertę na rynku '.$marketplace.'.'];

                continue;
            }

            $sku = trim((string) $product->product_code);
            if ($sku === '') {
                $failed[] = $label + ['error' => 'Produkt nie ma kodu (SKU) — eBay identyfikuje po nim pozycję.'];

                continue;
            }

            $net = (float) ($prices[$product->id] ?? 0);
            if ($net <= 0) {
                $failed[] = $label + ['error' => 'Brak ceny w cenniku schematu (albo cena 0).'];

                continue;
            }

            $draft = $this->builder->build($product, $scheme);
            $blocking = array_values(array_filter($draft['notes'], fn ($n) => str_contains($n, 'WYMAGAN')));
            if ($blocking !== []) {
                $failed[] = $label + ['error' => implode(' ', $blocking)];

                continue;
            }

            try {
                $result = $this->publishOne($client, $scheme, $product, $draft, $sku, $net, $marketplace);
                $published[] = $label + $result;
            } catch (\Throwable $e) {
                Log::warning('eBay publish: błąd wystawiania', $label + ['error' => $e->getMessage()]);
                $failed[] = $label + ['error' => mb_substr($e->getMessage(), 0, 400)];
            }
        }

        return ['published' => $published, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Jeden produkt: pozycja magazynowa → oferta → (opcjonalnie) publikacja → zapis w ebay_offers.
     *
     * @return array{offer_id:string, listing_id:?string, status:string, price:float, notes:list<string>}
     */
    private function publishOne(
        EbayInventoryClient $client,
        EbayScheme $scheme,
        Product $product,
        array $draft,
        string $sku,
        float $net,
        string $marketplace,
    ): array {
        $gross = $scheme->grossPrice($net);
        $stock = (int) $scheme->default_stock;

        $client->createOrReplaceInventoryItem(
            $sku, $marketplace, $draft['title'], $draft['description'],
            $draft['aspects'], $draft['images'], $stock,
        );

        // Ponowne wystawienie po nieudanej próbie: oferta dla tego SKU może już istnieć,
        // a eBay odrzuciłby duplikat. Wtedy używamy istniejącej zamiast tworzyć drugą.
        $existing = collect($client->offersForSku($sku, $marketplace))->first();
        $offerId = $existing['offerId'] ?? null;

        if (! $offerId) {
            $offerId = $client->createOffer($this->offerPayload($scheme, $sku, $marketplace, $gross, $stock, $draft), $marketplace);
        }
        if ($offerId === '') {
            throw new \RuntimeException('eBay nie zwrócił identyfikatora oferty.');
        }

        $listingId = null;
        $status = 'INACTIVE';
        if ($scheme->publishesActive()) {
            $listingId = $client->publishOffer((string) $offerId, $marketplace);
            $status = 'Active';
        }

        // Lustro w ebay_offers — kluczem jest (item_id, sku, marketplace). Dopóki oferta nie jest
        // opublikowana, nie ma ItemID, więc trzymamy tam offerId z prefiksem, żeby nie zderzyć
        // się z prawdziwymi ItemID starych aukcji z Trading.
        EbayOffer::updateOrCreate(
            [
                'item_id' => $listingId ?: ('draft:'.$offerId),
                'sku' => $sku,
                'marketplace' => $marketplace,
            ],
            [
                'product_id' => $product->id,
                'match_type' => 'auto',
                'title' => $draft['title'],
                'price' => $gross,
                'currency' => $this->currency($marketplace),
                'quantity' => $stock,
                'quantity_sold' => 0,
                'listing_status' => $status,
                'listing_url' => $listingId ? $this->listingUrl($listingId, $marketplace) : null,
                'first_seen' => now(),
                'last_seen' => now(),
            ]
        );

        return [
            'offer_id' => (string) $offerId,
            'listing_id' => $listingId ?: null,
            'status' => $status,
            'price' => $gross,
            'notes' => $draft['notes'],
        ];
    }

    /** Payload oferty (Inventory API). Polityki i lokalizacja tylko gdy schemat je ma. */
    private function offerPayload(EbayScheme $scheme, string $sku, string $marketplace, float $gross, int $stock, array $draft): array
    {
        $payload = [
            'sku' => $sku,
            'marketplaceId' => $marketplace,
            'format' => 'FIXED_PRICE',
            'availableQuantity' => $stock,
            'categoryId' => (string) $draft['category_id'],
            'listingDescription' => $draft['description'],
            'pricingSummary' => [
                'price' => ['value' => number_format($gross, 2, '.', ''), 'currency' => $this->currency($marketplace)],
            ],
        ];

        $policies = array_filter([
            'fulfillmentPolicyId' => $scheme->fulfillment_policy_id,
            'paymentPolicyId' => $scheme->payment_policy_id,
            'returnPolicyId' => $scheme->return_policy_id,
        ]);
        if ($policies !== []) {
            $payload['listingPolicies'] = $policies;
        }
        if ($scheme->merchant_location_key) {
            $payload['merchantLocationKey'] = $scheme->merchant_location_key;
        }

        return $payload;
    }

    /** Czego brakuje, żeby ofertę dało się AKTYWOWAĆ (szkic przejdzie bez tego). */
    private function missingForActivation(EbayScheme $scheme): array
    {
        return array_values(array_filter([
            $scheme->fulfillment_policy_id ? null : 'polityka dostawy',
            $scheme->payment_policy_id ? null : 'polityka płatności',
            $scheme->return_policy_id ? null : 'polityka zwrotów',
            $scheme->merchant_location_key ? null : 'lokalizacja magazynu',
        ]));
    }

    private function currency(string $marketplace): string
    {
        return match (strtoupper($marketplace)) {
            'EBAY_GB' => 'GBP',
            'EBAY_US' => 'USD',
            'EBAY_PL' => 'PLN',
            default => 'EUR',
        };
    }

    private function listingUrl(string $listingId, string $marketplace): string
    {
        $host = match (strtoupper($marketplace)) {
            'EBAY_DE' => 'ebay.de', 'EBAY_AT' => 'ebay.at', 'EBAY_FR' => 'ebay.fr',
            'EBAY_ES' => 'ebay.es', 'EBAY_IT' => 'ebay.it', 'EBAY_PL' => 'ebay.pl',
            'EBAY_GB' => 'ebay.co.uk', default => 'ebay.com',
        };

        return "https://www.{$host}/itm/{$listingId}";
    }
}
