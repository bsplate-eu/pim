<?php

namespace App\Http\Controllers\Admin\Connect;

use App\Http\Controllers\Admin\Controller;
use App\Jobs\RunEbayOffersSync;
use App\Jobs\RunEbayPriceUpdate;
use App\Models\Ebay\EbayOffer;
use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Scrap\EbaySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Argo Connect → Integracja eBay → Oferta. Nasze aukcje (ebay_offers) z mapowaniem po SKU.
 * Taby = rynki (marketplace) + „Wszystkie". Wzorzec listy/mapowania jak Scope\ScopeRumuniController.
 */
class EbayOffersController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = EbaySettings::first();
        $perPage = $this->resolvePerPage($request);
        $marketplace = $request->input('filter.marketplace');

        $counts = EbayOffer::selectRaw('marketplace, COUNT(*) as c')
            ->groupBy('marketplace')
            ->pluck('c', 'marketplace');

        $offers = QueryBuilder::for(EbayOffer::query()->with('product:id,name,product_code'))
            ->allowedFilters([
                AllowedFilter::callback('search', function ($q, $value) {
                    $q->where(function ($w) use ($value) {
                        $w->where('title', 'like', "%{$value}%")
                            ->orWhere('sku', 'like', "%{$value}%")
                            ->orWhere('item_id', 'like', "%{$value}%");
                    });
                }),
                AllowedFilter::callback('mapped', function ($q, $value) {
                    (string) $value === '1' ? $q->whereNotNull('product_id') : $q->whereNull('product_id');
                }),
                AllowedFilter::exact('marketplace'),
            ])
            ->defaultSort('title')
            ->allowedSorts(['title', 'sku', 'price', 'quantity', 'listing_status', 'product_id', 'last_seen'])
            ->paginate($perPage)
            ->withQueryString();

        $offers->getCollection()->each(function (EbayOffer $o) {
            if ($o->product) {
                $o->product->name = $this->namePl($o->product->name);
            }
        });

        return Inertia::render('Connect/Integrations/Ebay/Offers', [
            'offers' => $offers,
            'marketplaces' => $counts,
            'pricelists' => Pricelist::orderBy('name')->get(['id', 'name', 'currency']),
            'total' => EbayOffer::count(),
            'unmapped' => EbayOffer::whereNull('product_id')->count(),
            'sort' => $request->input('sort', 'title'),
            'per_page' => $perPage,
            'filters' => [
                'search' => $request->input('filter.search'),
                'mapped' => $request->input('filter.mapped'),
                'marketplace' => $marketplace,
            ],
            'meta' => [
                'oauth_connected' => $settings && $settings->isOauthConnected(),
                'has_credentials' => $settings && $settings->hasCredentials(),
            ],
        ]);
    }

    /** „Pobierz oferty" — pełny pomiar własnych aukcji w tle (Sell/Trading). */
    public function fetch(Request $request): JsonResponse
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            return response()->json([
                'ok' => false,
                'message' => 'Konto eBay nie jest połączone. Połącz je w sekcji ustawień (Połącz konto eBay).',
            ], 422);
        }

        $marketplace = $request->input('marketplace') ?: null;
        RunEbayOffersSync::dispatch($marketplace);

        return response()->json([
            'ok' => true,
            'message' => 'Pobieranie ofert uruchomione w tle (~kilka minut). Odśwież stronę za chwilę.',
        ]);
    }

    /** Ręczne przypisanie naszego produktu do oferty (lub odpięcie gdy puste). */
    public function assign(Request $request, EbayOffer $offer): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        $offer->forceFill([
            'product_id' => $data['product_id'] ?? null,
            'match_type' => ! empty($data['product_id']) ? 'manual' : null,
        ])->save();

        $offer->load('product:id,name,product_code');
        $prod = $offer->product;

        return response()->json(['ok' => true, 'product' => $prod ? [
            'id' => $prod->id,
            'product_code' => $prod->product_code,
            'name' => $this->namePl($prod->name),
        ] : null]);
    }

    /** Pula ofert do operacji: konkretne ids[] albo WSZYSTKIE pasujące filtrowi (all=true). Zawsze tylko ZMAPOWANE. */
    private function operationOffers(array $data)
    {
        $q = EbayOffer::query()->whereNotNull('product_id');

        if (! empty($data['all'])) {
            if (! empty($data['marketplace'])) {
                $q->where('marketplace', $data['marketplace']);
            }
            if (! empty($data['search'])) {
                $s = $data['search'];
                $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")
                    ->orWhere('sku', 'like', "%{$s}%")
                    ->orWhere('item_id', 'like', "%{$s}%"));
            }
        } else {
            $q->whereIn('id', $data['ids'] ?? []);
        }

        return $q;
    }

    /** Walidacja wspólna dla preview/apply operacji cenowej. */
    private function priceOpData(Request $request): array
    {
        return $request->validate([
            'pricelist_id' => ['required', 'integer', 'exists:pricelists,id'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'all' => ['nullable', 'boolean'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'marketplace' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
        ]);
    }

    /** PODGLĄD zmiany cen (cena z cennika netto × (1+VAT) = brutto eBay). NIE dotyka eBay. */
    public function priceUpdatePreview(Request $request): JsonResponse
    {
        $data = $this->priceOpData($request);
        $vat = (float) ($data['vat'] ?? 0);
        $prices = PricelistProduct::exportPriceMap($data['pricelist_id']);

        $offers = $this->operationOffers($data)->get(['id', 'item_id', 'sku', 'title', 'price', 'currency', 'product_id']);

        $rows = $offers->map(function (EbayOffer $o) use ($prices, $vat) {
            $net = (float) ($prices[$o->product_id] ?? 0);
            $new = $net > 0 ? round($net * (1 + $vat / 100), 2) : 0.0;

            return ['title' => $o->title, 'sku' => $o->sku, 'old' => $o->price, 'new' => $new, 'currency' => $o->currency];
        })->filter(fn (array $r) => $r['new'] > 0)->values();

        return response()->json([
            'count' => $rows->count(),
            'skipped' => $offers->count() - $rows->count(),  // brak ceny w cenniku → pominięte
            'pricelist' => Pricelist::find($data['pricelist_id'])?->name,
            'sample' => $rows->take(15),
        ]);
    }

    /** WYKONAJ zmianę cen na eBay (w tle, ReviseInventoryStatus). Wymaga połączonego konta. */
    public function priceUpdateApply(Request $request): JsonResponse
    {
        $data = $this->priceOpData($request);

        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            return response()->json(['ok' => false, 'message' => 'Konto eBay nie jest połączone — nie mogę zmieniać cen.'], 422);
        }

        $ids = $this->operationOffers($data)->pluck('id')->all();
        if (empty($ids)) {
            return response()->json(['ok' => false, 'message' => 'Brak zmapowanych ofert do zmiany.'], 422);
        }

        RunEbayPriceUpdate::dispatch($ids, (int) $data['pricelist_id'], (float) ($data['vat'] ?? 0));

        return response()->json([
            'ok' => true,
            'message' => 'Zmiana cen ' . count($ids) . ' ofert uruchomiona w tle (eBay ReviseInventoryStatus). To REALNE ceny — sprawdź wynik na eBay.',
        ]);
    }

    /** Polska nazwa z pola name (JSON matrycy tłumaczeń) lub surowa. */
    private function namePl(?string $name): string
    {
        if (! $name) {
            return '';
        }
        $d = json_decode($name, true);
        if (is_array($d)) {
            $pl = $d['pl'] ?? null;
            if (is_string($pl) && $pl !== '') {
                return $pl;
            }
            $first = reset($d);

            return is_string($first) ? $first : $name;
        }

        return $name;
    }

    private function resolvePerPage(Request $request): int
    {
        $pp = $request->integer('per_page', 50);

        return in_array($pp, [50, 100, 250, 500], true) ? $pp : 50;
    }
}
