<?php

namespace App\Http\Controllers\Admin\Connect\Marketplace;

use App\Http\Controllers\Admin\Controller;
use App\Models\Ebay\EbayOffer;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\Listing\EbayKtypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Argo Connect → Marketplace → eBay → kType (kompatybilność pojazdów).
 *
 * Dwie rzeczy, których nie dało się zrobić z CLI: podejrzeć fitment konkretnej aukcji
 * i puścić automat na aukcje wybrane klikaniem zamiast przepisywania ItemID.
 *
 * Automat to `ebay:ktype-push` wołany przez EbayKtypeService — patrz tam, dlaczego nie
 * przepisujemy go na serwis.
 */
class EbayKtypeController extends Controller
{
    public function index(Request $request, EbayKtypeService $ktype): Response
    {
        $settings = EbaySettings::first();
        $registry = $ktype->registry();

        $marketplace = strtoupper((string) $request->input('marketplace', 'EBAY_DE'));
        $fitment = (string) $request->input('fitment', '');   // '' | 'with' | 'without' | 'unknown'
        $search = trim((string) $request->input('search', ''));

        // Fitment jest własnością AUKCJI, nie wariantu — lista idzie per item_id.
        $q = EbayOffer::query()
            ->where('marketplace', $marketplace)
            ->where('listing_status', 'Active')
            ->selectRaw('MIN(id) as id, item_id, marketplace, MIN(title) as title, MIN(sku) as sku,
                         MIN(listing_url) as listing_url, MIN(product_id) as product_id,
                         MAX(compat_count) as compat_count, MAX(compat_checked_at) as compat_checked_at')
            ->groupBy('item_id', 'marketplace');

        // Tytuł/SKU/ItemID to zwykłe kolumny, więc filtrujemy PRZED grupowaniem (WHERE, nie HAVING)
        // — szybciej i bez zgadywania, co po agregacji znaczy „title". Aukcja wejdzie na listę,
        // gdy pasuje choć jeden jej wariant, i o to chodzi.
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('title', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('item_id', 'like', "%{$search}%"));
        }

        match ($fitment) {
            'with' => $q->havingRaw('MAX(compat_count) > 0'),
            'without' => $q->havingRaw('MAX(compat_count) = 0'),
            'unknown' => $q->havingRaw('MAX(compat_count) IS NULL'),
            default => null,
        };

        $offers = $q->orderBy('title')->paginate($request->integer('per_page', 50))->withQueryString();

        $offers->getCollection()->transform(fn ($o) => [
            'id' => (int) $o->id,
            'item_id' => $o->item_id,
            'sku' => $o->sku,
            'title' => $o->title,
            'listing_url' => $o->listing_url,
            'product_id' => $o->product_id,
            'compat_count' => $o->compat_count === null ? null : (int) $o->compat_count,
            'compat_checked_at' => $o->compat_checked_at,
            // Status z rejestru automatu — mówi, DLACZEGO aukcja nie dostała fitmentu.
            'ktype_status' => $registry[$o->item_id] ?? null,
        ]);

        // Pokrycie liczone na całym rynku, nie na stronie — inaczej licznik skakałby przy paginacji.
        $base = EbayOffer::where('marketplace', $marketplace)->where('listing_status', 'Active');
        $totals = [
            'listings' => (clone $base)->distinct('item_id')->count('item_id'),
            'with' => (clone $base)->where('compat_count', '>', 0)->distinct('item_id')->count('item_id'),
            'without' => (clone $base)->where('compat_count', '=', 0)->distinct('item_id')->count('item_id'),
            'unknown' => (clone $base)->whereNull('compat_count')->distinct('item_id')->count('item_id'),
        ];

        return Inertia::render('Connect/Marketplace/Ebay/Ktype/Index', [
            'offers' => $offers,
            'marketplaces' => EbayOffer::query()->select('marketplace')->distinct()->orderBy('marketplace')->pluck('marketplace'),
            'totals' => $totals,
            'maxBatch' => EbayKtypeService::MAX_BATCH,
            'registryCounts' => array_count_values(array_filter($registry, 'is_string')),
            'filters' => ['marketplace' => $marketplace, 'fitment' => $fitment, 'search' => $search],
            'meta' => ['oauth_connected' => (bool) $settings?->isOauthConnected()],
        ]);
    }

    /** Podgląd fitmentu jednej aukcji — lista pojazdów prosto z eBaya. Sam odczyt. */
    public function fitment(EbayOffer $offer, EbayKtypeService $ktype): JsonResponse
    {
        try {
            $compat = $ktype->fitment($offer);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'item_id' => $offer->item_id,
            'title' => $offer->title,
            'listing_url' => $offer->listing_url,
            'count' => $compat['count'],
            // 200 pojazdów w modalu i tak nikt nie przeczyta; liczba wyżej mówi całość.
            'list' => array_slice($compat['list'], 0, 60),
        ]);
    }

    /**
     * Ręczne dopasowanie — krok 1: jakie właściwości pojazdu ma kategoria tej aukcji
     * i jakie są dostępne wartości (kaskada marka → model → platforma → rok).
     *
     * Bez `property` zwraca same nazwy właściwości; z `property` — listę wartości
     * zawężoną tym, co użytkownik już wybrał.
     */
    public function vehicleOptions(Request $request, EbayOffer $offer, EbayKtypeService $ktype): JsonResponse
    {
        $data = $request->validate([
            'property' => ['nullable', 'string', 'max:60'],
            'filters' => ['nullable', 'array'],
        ]);

        try {
            $props = $ktype->vehicleProperties($offer);

            if (empty($data['property'])) {
                return response()->json(['properties' => $props]);
            }

            return response()->json([
                'properties' => $props,
                'property' => $data['property'],
                'values' => $ktype->vehicleValues($offer, $data['property'], $data['filters'] ?? []),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Ręczne dopasowanie — krok 2: zapisz złożoną listę pojazdów na aukcję.
     * ZASTĘPUJE dotychczasowy fitment (tak działa ReviseFixedPriceItem) — UI o tym uprzedza.
     */
    public function applyManual(Request $request, EbayOffer $offer, EbayKtypeService $ktype): JsonResponse
    {
        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:1000'],
            'entries.*' => ['array'],
        ]);

        // Do eBaya lecą wyłącznie pary nazwa→wartość, obie niepuste. Pusty wpis wywróciłby
        // całe żądanie, a wtedy aukcja zostaje bez fitmentu, który przed chwilą miała.
        $entries = [];
        foreach ($data['entries'] as $entry) {
            $clean = [];
            foreach ($entry as $name => $value) {
                if (is_string($name) && is_scalar($value) && trim((string) $value) !== '') {
                    $clean[$name] = trim((string) $value);
                }
            }
            if ($clean !== []) {
                $entries[] = $clean;
            }
        }

        try {
            return response()->json($ktype->applyManual($offer, $entries));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Odśwież liczniki fitmentu dla zaznaczonych aukcji (bez listy pojazdów). */
    public function refresh(Request $request, EbayKtypeService $ktype): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:'.EbayKtypeService::MAX_BATCH],
            'ids.*' => ['integer'],
        ]);

        try {
            return response()->json($ktype->refreshCounts($data['ids']));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Automat kType na zaznaczonych aukcjach.
     * `apply=false` (domyślnie) = dry-run: pokazuje, co by poleciało, nie dotykając eBaya.
     */
    public function run(Request $request, EbayKtypeService $ktype): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'marketplace' => ['required', 'string', 'max:16'],
            'apply' => ['nullable', 'boolean'],
            'from_title' => ['nullable', 'boolean'],
        ]);

        $apply = (bool) ($data['apply'] ?? false);

        if ($apply && ! EbaySettings::first()?->isOauthConnected()) {
            return response()->json(['error' => 'Konto eBay nie jest połączone — nie mogę wysyłać fitmentu.'], 422);
        }

        $itemIds = EbayOffer::whereIn('id', $data['ids'])->pluck('item_id')->unique()->values()->all();

        try {
            $result = $ktype->runAutomat($itemIds, $data['marketplace'], $apply, (bool) ($data['from_title'] ?? false));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Po realnej wysyłce liczniki na ekranie są nieaktualne — odświeżamy je od razu,
        // żeby użytkownik zobaczył efekt, a nie musiał klikać drugiego przycisku.
        if ($apply && $result['ok']) {
            try {
                $ktype->refreshCounts($data['ids']);
            } catch (\Throwable) {
                // Odświeżenie licznika to kosmetyka — jego błąd nie może przykryć udanej wysyłki.
            }
        }

        return response()->json($result + ['applied' => $apply, 'items' => count($itemIds)]);
    }
}
