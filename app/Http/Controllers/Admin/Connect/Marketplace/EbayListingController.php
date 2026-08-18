<?php

namespace App\Http\Controllers\Admin\Connect\Marketplace;

use App\Http\Controllers\Admin\Controller;
use App\Models\Category;
use App\Models\Ebay\EbayOffer;
use App\Models\Ebay\EbayScheme;
use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Models\Scrap\EbaySettings;
use App\Models\Source;
use App\Services\Ebay\Listing\EbayOfferDraftBuilder;
use App\Services\Ebay\Listing\EbayOfferPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Argo Connect → Marketplace → eBay → Wystawianie (etapy D + E).
 * Wzorzec: Connect\Marketplace\AllegroListingController z OMS ARGO.
 *
 * Lista produktów PIM → zaznacz → „Wystaw wg schematu" → PODGLĄD (nic nie wysyła) → publikacja.
 * Oferty powstają jako szkice, chyba że schemat ma `publication_mode = active`.
 */
class EbayListingController extends Controller
{
    public function index(Request $request): Response
    {
        $schemes = EbayScheme::enabled()->with(['category', 'template', 'pricelist'])->orderBy('name')->get();

        // Cennik i przelicznik bierzemy ze schematu wybranego w filtrze (albo pierwszego gotowego),
        // żeby cena na liście była TĄ SAMĄ, którą wyśle publikacja — a nie orientacyjną.
        $scheme = $request->filled('scheme_id')
            ? $schemes->firstWhere('id', (int) $request->input('scheme_id'))
            : $schemes->first(fn (EbayScheme $s) => $s->isReady());

        $pricelistId = $scheme?->pricelist_id;
        $marketplace = $scheme?->marketplace;

        // „Wystawiony" liczymy w obrębie RYNKU schematu — ten sam produkt bywa na DE i nie na FR.
        $listedIds = EbayOffer::query()
            ->when($marketplace, fn ($q) => $q->where('marketplace', $marketplace))
            ->whereNotNull('product_id')->distinct()->pluck('product_id')->all();

        $products = QueryBuilder::for(Product::class)
            ->allowedFilters([
                AllowedFilter::callback('search', fn ($q, $v) => $q->where(fn ($w) => $w
                    ->where('product_code', 'like', "%{$v}%")
                    ->orWhere('name->pl', 'like', "%{$v}%")
                    ->orWhere('name->de', 'like', "%{$v}%"))),
                AllowedFilter::callback('listed', function ($q, $v) use ($listedIds) {
                    if ($v === '' || $v === null) {
                        return;
                    }
                    filter_var($v, FILTER_VALIDATE_BOOLEAN)
                        ? $q->whereIn('id', $listedIds ?: [0])
                        : $q->whereNotIn('id', $listedIds ?: [0]);
                }),
                // Gotowość cenowa — brak ceny widać PRZED zaznaczeniem, nie jako błąd po kliknięciu.
                AllowedFilter::callback('priced', function ($q, $v) use ($pricelistId) {
                    if ($v === '' || $v === null || ! $pricelistId) {
                        return;
                    }
                    $hasPrice = fn ($sub) => $sub->from('pricelist_product')
                        ->whereColumn('pricelist_product.product_id', 'products.id')
                        ->where('pricelist_product.pricelist_id', $pricelistId)
                        ->where('pricelist_product.price', '>', 0);

                    filter_var($v, FILTER_VALIDATE_BOOLEAN)
                        ? $q->whereExists($hasPrice)
                        : $q->whereNotExists($hasPrice);
                }),
                AllowedFilter::callback('source', fn ($q, $v) => $q->where('source_id', $v)),
                AllowedFilter::callback('enabled', fn ($q, $v) => $q->where('enabled', $v)),
            ])
            ->defaultSort('-id')
            ->allowedSorts('id', 'product_code');

        // „Zaznacz wszystkie na wszystkich stronach" — same ID wg aktualnego filtra.
        if ($request->wantsJson() && $request->boolean('bulk_select_all')) {
            return response()->json((clone $products)->select('id')->pluck('id'));
        }

        $products = $products->with('media')->paginate($request->integer('per_page', 50))->withQueryString();

        $pageIds = $products->getCollection()->pluck('id')->all();
        $offers = EbayOffer::query()->whereIn('product_id', $pageIds)
            ->when($marketplace, fn ($q) => $q->where('marketplace', $marketplace))
            ->get()->groupBy('product_id');
        $prices = $pricelistId
            ? PricelistProduct::where('pricelist_id', $pricelistId)->whereIn('product_id', $pageIds)->pluck('price', 'product_id')
            : collect();

        $products->getCollection()->transform(function (Product $p) use ($offers, $prices, $scheme) {
            $names = array_filter($p->getTranslations('name'));
            $net = (float) ($prices[$p->id] ?? 0);
            $productOffers = $offers->get($p->id, collect());

            return [
                'id' => $p->id,
                'product_code' => $p->product_code,
                'name' => $names['pl'] ?? $names['de'] ?? (reset($names) ?: ''),
                'thumbnail' => $p->media?->first()?->getUrl(),
                'is_listed' => $productOffers->isNotEmpty(),
                'price' => $net > 0 && $scheme ? $scheme->grossPrice($net) : null,
                'edit_url' => route('crafter.products.edit', $p->id),
                'listings' => $productOffers->map(fn (EbayOffer $o) => [
                    'status' => $o->listing_status,
                    'url' => $o->listing_url,
                ])->values()->all(),
            ];
        });

        $settings = EbaySettings::first();

        return Inertia::render('Connect/Marketplace/Ebay/Listing/Index', [
            'products' => $products,
            'schemes' => $schemes->map(fn (EbayScheme $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'marketplace' => $s->marketplace,
                'publication_mode' => $s->publication_mode,
                'ready' => $s->isReady(),
                'problems' => $s->problems(),
            ]),
            'selectedSchemeId' => $scheme?->id,
            'priceSource' => $scheme ? [
                'scheme' => $scheme->name,
                'pricelist' => Pricelist::find($pricelistId)?->name,
                'multiplier' => $scheme->price_multiplier,
                'tax' => $scheme->tax_percent,
            ] : null,
            'listedCount' => count($listedIds),
            'sources' => Source::query()->orderBy('order')->orderBy('id')->get(['id', 'name']),
            'maxBatch' => EbayOfferPublishService::MAX_BATCH,
            'meta' => [
                'oauth_connected' => (bool) $settings?->isOauthConnected(),
                'has_credentials' => (bool) $settings?->hasCredentials(),
            ],
            'filters' => [
                'search' => $request->input('filter.search'),
                'listed' => $request->input('filter.listed'),
                'priced' => $request->input('filter.priced'),
                'source' => $request->input('filter.source'),
            ],
        ]);
    }

    /**
     * PODGLĄD wystawienia — buduje szkice dla zaznaczonych produktów, ale NIC nie wysyła.
     * Pokazuje, co automat złoży i co go zablokuje.
     */
    public function publishPreview(Request $request, EbayOfferDraftBuilder $builder): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'scheme_id' => ['required', 'integer', 'exists:ebay_schemes,id'],
        ]);

        $scheme = EbayScheme::with(['category', 'template', 'pricelist'])->findOrFail($data['scheme_id']);
        $products = Product::with(['attributeValues.attribute', 'media'])->whereIn('id', $data['ids'])->get();

        $prices = $scheme->pricelist_id
            ? PricelistProduct::where('pricelist_id', $scheme->pricelist_id)->whereIn('product_id', $products->pluck('id'))->pluck('price', 'product_id')
            : collect();

        $items = $products->map(function (Product $p) use ($builder, $scheme, $prices) {
            $draft = $builder->build($p, $scheme);
            $net = (float) ($prices[$p->id] ?? 0);

            return [
                'product_code' => $p->product_code,
                'title' => $draft['title'],
                'title_length' => mb_strlen($draft['title']),
                'aspects_count' => count($draft['aspects']),
                'images_count' => count($draft['images']),
                'price' => $net > 0 ? $scheme->grossPrice($net) : null,
                'blocking' => array_values(array_filter($draft['notes'], fn ($n) => str_contains($n, 'WYMAGAN'))),
                'notes' => array_values(array_filter($draft['notes'], fn ($n) => ! str_contains($n, 'WYMAGAN'))),
            ];
        });

        return response()->json([
            'scheme' => $scheme->name,
            'marketplace' => $scheme->marketplace,
            'publication_mode' => $scheme->publication_mode,
            'count' => $items->count(),
            'blocked' => $items->filter(fn ($i) => $i['blocking'] !== [])->count(),
            'no_price' => $items->filter(fn ($i) => $i['price'] === null)->count(),
            'scheme_problems' => $scheme->problems(),
            'items' => $items->take(25),
        ]);
    }

    /** FAKTYCZNE wystawienie zaznaczonych produktów wg schematu. */
    public function publish(Request $request, EbayOfferPublishService $publisher): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:'.EbayOfferPublishService::MAX_BATCH],
            'ids.*' => ['integer'],
            'scheme_id' => ['required', 'integer', 'exists:ebay_schemes,id'],
        ]);

        $scheme = EbayScheme::with(['category', 'template', 'pricelist'])->findOrFail($data['scheme_id']);
        $products = Product::with(['attributeValues.attribute', 'media'])->whereIn('id', $data['ids'])->get();

        try {
            $result = $publisher->publish($scheme, $products);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'scheme' => $scheme->name,
            'marketplace' => $scheme->marketplace,
            'publication_mode' => $scheme->publication_mode,
            'published' => $result['published'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
        ]);
    }
}
