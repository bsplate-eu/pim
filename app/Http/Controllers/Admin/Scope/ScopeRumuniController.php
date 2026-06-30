<?php

namespace App\Http\Controllers\Admin\Scope;

use App\Http\Controllers\Admin\Controller;
use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Models\Scrap\EbaySettings;
use App\Models\Scrap\ScrapProduct;
use App\Models\Scrap\ScrapSource;
use App\Services\Scrap\CurrencyConverter;
use App\Services\Scrap\ShopScrapService;
use Illuminate\Support\Str;
use App\Services\Ebay\EbayScrapService;
use App\Services\Scrap\ProductMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Argo Scope → Scrapy → Rumuni (Scut Protection).
 * Taby: Raport | Ebay | Sklep 1 (stahl) | Sklep 2.
 * Każdy tab = kanał konkurenta. Tu dokładamy kolejne scrapy ich ofert.
 */
class ScopeRumuniController extends Controller
{
    /** Źródła (kanały) konkurenta — kolejne dokładamy tutaj. eBay: 6 rynków (każdy osobny katalog). */
    private const SOURCES = [
        'ebay', 'ebay_fr', 'ebay_it', 'ebay_es', 'ebay_gb', 'ebay_ch',
        'stahl', 'wegry', 'rumunia', 'francja', 'czechy', 'hiszpania',
    ];

    /** Krótkie etykiety tabów (długie nazwy sklepów są w ShopScrapService::SHOPS). */
    private const TAB_LABELS = [
        'ebay' => 'eBay.de', 'ebay_fr' => 'eBay.fr', 'ebay_it' => 'eBay.it',
        'ebay_es' => 'eBay.es', 'ebay_gb' => 'eBay.co.uk', 'ebay_ch' => 'eBay.ch',
        'stahl' => 'Niemcy', 'wegry' => 'Węgry', 'rumunia' => 'Rumunia',
        'francja' => 'Francja', 'czechy' => 'Czechy', 'hiszpania' => 'Hiszpania',
    ];

    public function index(Request $request): Response
    {
        $settings = EbaySettings::first();
        $perPage = $this->resolvePerPage($request);

        // Wszystkie kanały data-driven: config cennika + ceny porównawcze + oferty + meta + nieprzypisane per source.
        $channels = [];
        $configs = [];
        $meta = [];
        $unmapped = [];
        foreach (self::SOURCES as $src) {
            $cfg = $this->channelConfig($src);
            $configs[$src] = [
                'pricelist_id' => $cfg->compare_pricelist_id,
                'vat' => $cfg->compare_vat,
                'target_pricelist_id' => $cfg->target_pricelist_id,
            ];
            $channels[$src] = $this->channelProducts($src, $request, $this->comparePrices($cfg), $cfg->compare_pricelist_id, $cfg->compare_vat, $perPage);
            $meta[$src] = $this->channelMeta($src, $settings);
            $unmapped[$src] = ScrapProduct::where('source', $src)->whereNull('product_id')->count();
        }

        return Inertia::render('Scope/Scrapy/Rumuni/Index', [
            'channels' => $channels,
            'meta' => $meta,
            'configs' => $configs,
            'unmapped' => $unmapped,
            'order' => self::SOURCES,
            'labels' => self::TAB_LABELS,
            'sort' => $request->input('sort', 'title'),
            'per_page' => $perPage,
            'filters' => [
                'search' => $request->input('filter.search'),
                'mapped' => $request->input('filter.mapped'),
                'has_hn' => $request->input('filter.has_hn'),
                'has_compare' => $request->input('filter.has_compare'),
            ],
            'pricelists' => Pricelist::orderBy('name')->get(['id', 'name', 'currency']),
        ]);
    }

    private function channelProducts(string $source, Request $request, array $comparePrices = [], ?int $comparePricelistId = null, ?float $compareVat = null, int $perPage = 50)
    {
        $compareRate = $this->pricelistEurRate($comparePricelistId) ?? 1.0; // cennik PLN/CZK → EUR (sorty „diff")
        $offerRate = $this->channelEurRate($source);                        // cena oferty HUF/RON → EUR (sorty „diff")

        $paginated = QueryBuilder::for(ScrapProduct::query()->where('scrap_products.source', $source)->with('product:id,name,product_code'))
            ->allowedFilters([
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('scrap_products.title', 'like', "%{$value}%")
                            ->orWhere('scrap_products.herstellernummer', 'like', "%{$value}%")
                            ->orWhere('scrap_products.ean', 'like', "%{$value}%");
                    });
                }),
                AllowedFilter::callback('mapped', function ($query, $value) {
                    (string) $value === '1' ? $query->whereNotNull('scrap_products.product_id') : $query->whereNull('scrap_products.product_id');
                }),
                AllowedFilter::callback('has_hn', function ($query, $value) {
                    (string) $value === '1' ? $query->whereNotNull('scrap_products.herstellernummer') : $query->whereNull('scrap_products.herstellernummer');
                }),
                AllowedFilter::callback('has_compare', function ($query, $value) use ($comparePrices) {
                    $ids = array_keys($comparePrices);
                    if ((string) $value === '1') {
                        empty($ids) ? $query->whereRaw('1 = 0') : $query->whereIn('scrap_products.product_id', $ids);
                    } elseif (! empty($ids)) {
                        $query->where(function ($q) use ($ids) {
                            $q->whereNull('scrap_products.product_id')->orWhereNotIn('scrap_products.product_id', $ids);
                        });
                    }
                }),
            ])
            ->defaultSort('title')
            ->allowedSorts([
                'title', 'price', 'herstellernummer', 'ean', 'last_seen', 'product_id', 'individual_price',
                AllowedSort::callback('compare_price', function ($query, bool $descending) use ($comparePricelistId) {
                    if (! $comparePricelistId) {
                        return;
                    }
                    $query->leftJoin('pricelist_product as cmp', function ($j) use ($comparePricelistId) {
                        $j->on('cmp.product_id', '=', 'scrap_products.product_id')
                            ->where('cmp.pricelist_id', '=', $comparePricelistId);
                    })->orderBy('cmp.price', $descending ? 'desc' : 'asc')->select('scrap_products.*');
                }),
                // Różnica (EUR) = cena oferty×kurs − cena cennik (netto×(1+VAT)×kurs). Brak ceny/cennika (NULL) na końcu.
                AllowedSort::callback('diff', function ($query, bool $descending) use ($comparePricelistId, $compareVat, $compareRate, $offerRate) {
                    if (! $comparePricelistId) {
                        return;
                    }
                    $mult = sprintf('%.6f', (1 + ((float) $compareVat) / 100) * $compareRate); // cennik → EUR brutto
                    $orate = sprintf('%.8f', $offerRate);                                      // oferta → EUR
                    $dir = $descending ? 'desc' : 'asc';
                    $query->leftJoin('pricelist_product as cmpd', function ($j) use ($comparePricelistId) {
                        $j->on('cmpd.product_id', '=', 'scrap_products.product_id')
                            ->where('cmpd.pricelist_id', '=', $comparePricelistId);
                    })
                        ->orderByRaw("(scrap_products.price * {$orate} - cmpd.price * {$mult}) IS NULL")
                        ->orderByRaw("(scrap_products.price * {$orate} - cmpd.price * {$mult}) {$dir}")
                        ->select('scrap_products.*');
                }),
                // Różnica % (EUR) = (oferta×kurs − cennik) / cennik. NULL (brak cennika/0) na końcu.
                AllowedSort::callback('diff_pct', function ($query, bool $descending) use ($comparePricelistId, $compareVat, $compareRate, $offerRate) {
                    if (! $comparePricelistId) {
                        return;
                    }
                    $mult = sprintf('%.6f', (1 + ((float) $compareVat) / 100) * $compareRate);
                    $orate = sprintf('%.8f', $offerRate);
                    $dir = $descending ? 'desc' : 'asc';
                    $expr = "(scrap_products.price * {$orate} - cmpp.price * {$mult}) / NULLIF(cmpp.price * {$mult}, 0)";
                    $query->leftJoin('pricelist_product as cmpp', function ($j) use ($comparePricelistId) {
                        $j->on('cmpp.product_id', '=', 'scrap_products.product_id')
                            ->where('cmpp.pricelist_id', '=', $comparePricelistId);
                    })
                        ->orderByRaw("({$expr}) IS NULL")
                        ->orderByRaw("({$expr}) {$dir}")
                        ->select('scrap_products.*');
                }),
            ])
            ->paginate($perPage, ['*'], $source . '_page')
            ->withQueryString();

        $fx = app(CurrencyConverter::class);
        $paginated->getCollection()->each(function (ScrapProduct $sp) use ($comparePrices, $fx) {
            if ($sp->product) {
                $sp->product->name = $this->namePl($sp->product->name);
            }
            $sp->compare_price = $sp->product_id ? ($comparePrices[$sp->product_id] ?? null) : null;

            // Cena oferty w EUR (źródło ≠ EUR → przeliczone po kursie EBC z dnia poprzedniego; brak kursu → null).
            $cur = strtoupper((string) $sp->currency);
            if ($sp->price === null) {
                $sp->price_eur = null;
            } elseif ($cur === '' || $cur === 'EUR') {
                $sp->price_eur = round((float) $sp->price, 2);
            } else {
                $rate = $fx->toEur($cur);
                $sp->price_eur = $rate ? round((float) $sp->price * $rate, 2) : null;
            }
        });

        return $paginated;
    }

    /** Ręczne pobranie ofert danego kanału do scrap_products. */
    public function sync(string $source): JsonResponse
    {
        if (! in_array($source, self::SOURCES, true)) {
            return response()->json(['ok' => false, 'message' => "Nieznany kanał: {$source}."], 404);
        }

        if (EbayScrapService::isMarket($source)) {
            $settings = EbaySettings::first();
            if (! $settings || ! $settings->hasCredentials()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Brak integracji eBay. Skonfiguruj ją w Connect → Integracje → Ebay.',
                ], 422);
            }
            \App\Jobs\RunEbayFullSync::dispatch($source);
            $label = EbayScrapService::MARKETS[$source]['label'] ?? $source;

            return response()->json([
                'ok' => true,
                'message' => "Pełny pomiar {$label} uruchomiony w tle (~kilka minut: ceny + kody + porównanie). Odśwież stronę za chwilę.",
            ]);
        }

        if (ShopScrapService::isShop($source)) {
            \App\Jobs\RunShopSync::dispatch($source);
            $label = ShopScrapService::SHOPS[$source]['label'] ?? $source;
            return response()->json([
                'ok' => true,
                'message' => "Pomiar sklepu {$label} uruchomiony w tle (~kilka minut). Odśwież stronę za chwilę.",
            ]);
        }

        return response()->json([
            'ok' => false,
            'message' => 'Scraper tego kanału jeszcze nie dodany.',
        ], 422);
    }

    /** „Przypisz do SKU" — masowe auto-mapowanie ofert kanału → nasze produkty (HN↔product_code, EAN↔ean). */
    public function matchProducts(Request $request): JsonResponse
    {
        $source = (string) $request->input('source', 'ebay');
        if (! in_array($source, self::SOURCES, true)) {
            return response()->json(['ok' => false, 'message' => "Nieznany kanał: {$source}."], 404);
        }

        $r = (new ProductMatcher())->matchSource($source);

        return response()->json([
            'ok' => true,
            'matched' => $r['matched'],
            'checked' => $r['checked'],
            'message' => "Przypisano {$r['matched']} z {$r['checked']} (SKU 1:1: {$r['sku_unique']}, duplikat→EAN: {$r['sku_by_ean']}, duplikat→nazwa: {$r['sku_by_name']}, sam EAN: {$r['ean_only']}).",
        ]);
    }

    /** Ręczne przypisanie naszego produktu do oferty (lub odpięcie gdy product_id puste). */
    public function assignProduct(Request $request, ScrapProduct $scrapProduct): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        $scrapProduct->forceFill([
            'product_id' => $data['product_id'] ?? null,
            'match_type' => ! empty($data['product_id']) ? 'manual' : null,
        ])->save();

        $scrapProduct->load('product:id,name,product_code');
        $prod = $scrapProduct->product;

        return response()->json(['ok' => true, 'product' => $prod ? [
            'id' => $prod->id,
            'product_code' => $prod->product_code,
            'name' => $this->namePl($prod->name),
        ] : null]);
    }

    /** Cena indywidualna (ręczna) oferty. Gdy ustawiona (> 0), ONA — nie cena źródła — idzie do cennika.
     *  Pusta / 0 = brak wpływu (zapisujemy null). */
    public function setIndividual(Request $request, ScrapProduct $scrapProduct): JsonResponse
    {
        $data = $request->validate([
            'individual_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $val = $data['individual_price'] ?? null;
        $scrapProduct->forceFill([
            'individual_price' => ($val !== null && (float) $val > 0) ? $val : null,
        ])->save();

        return response()->json(['ok' => true, 'individual_price' => $scrapProduct->individual_price]);
    }

    /** „Wyklucz" (trwałe) — zaznaczone = narzędzie NIE rusza tej pozycji w cenniku. ids[] + excluded(bool). */
    public function setExcluded(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'excluded' => ['required', 'boolean'],
        ]);

        ScrapProduct::whereIn('id', $data['ids'])->update(['excluded' => $data['excluded']]);

        return response()->json(['ok' => true]);
    }

    /** Wyszukiwarka naszych produktów do ręcznego mapowania (dropdown „dodaj z listy"). */
    public function searchProducts(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));

        $products = Product::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('product_code', 'like', "%{$q}%");
                });
            })
            ->orderBy('product_code')
            ->limit(20)
            ->get(['id', 'name', 'product_code'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'product_code' => $p->product_code,
                'name' => $this->namePl($p->name),
            ]);

        return response()->json($products);
    }

    /** Polska nazwa z pola name (JSON matrycy tłumaczeń) lub surowa, gdy to zwykły tekst. */
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

    /** Cena efektywna oferty w EUR (BRUTTO): indywidualna (gdy > 0) lub cena źródła, przeliczona na EUR
     *  gdy waluta ≠ EUR (np. HUF). Brak kursu → 0.0 (pozycja pomijana — nie wrzucamy obcej waluty do cennika EUR). */
    private function effectivePriceEur(ScrapProduct $s, CurrencyConverter $fx): float
    {
        $ind = (float) ($s->individual_price ?? 0);
        $base = $ind > 0 ? $ind : (float) $s->price;

        $cur = strtoupper((string) $s->currency);
        if ($cur === '' || $cur === 'EUR') {
            return $base;
        }
        $rate = $fx->toEur($cur);

        return $rate ? round($base * $rate, 2) : 0.0;
    }

    /** Brutto (eBay / cena indywidualna) → NETTO do zapisu w cenniku.
     *  eBay podaje brutto, cennik trzyma netto — odejmujemy stawkę VAT (compare_vat). */
    private function toNet(float $gross, float $vat): float
    {
        return $vat > 0 ? round($gross / (1 + $vat / 100), 2) : round($gross, 2);
    }

    /** Cena netto do cennika docelowego = NIŻSZA z: (cena ze scrapa/indywidualna → netto)
     *  i (cena z cennika porównawczego → netto). Gdy brak/0 ceny porównawczej → tylko ze scrapa. */
    private function targetNetPrice(ScrapProduct $s, float $vat, array $compareNet, CurrencyConverter $fx): float
    {
        $scrapNet = $this->toNet($this->effectivePriceEur($s, $fx), $vat);
        $cmp = (float) ($compareNet[$s->product_id] ?? 0);

        return ($cmp > 0 && $cmp < $scrapNet) ? $cmp : $scrapNet;
    }

    /** Dozwolone ilości na stronę: 50/100/250/500 (inne → 50). */
    private function resolvePerPage(Request $request): int
    {
        $pp = $request->integer('per_page', 50);

        return in_array($pp, [50, 100, 250, 500], true) ? $pp : 50;
    }

    /** Config kanału (cennik porównawczy/docelowy + VAT) — per source w scrap_sources. */
    private function channelConfig(string $source): ScrapSource
    {
        return ScrapSource::firstOrCreate(['source' => $source]);
    }

    /** Meta dowolnego kanału. eBay: integracja z EbaySettings (creds), statystyki z scrap_sources. Sklep: shopMeta. */
    private function channelMeta(string $source, ?EbaySettings $settings): array
    {
        if (! EbayScrapService::isMarket($source)) {
            return $this->shopMeta($source);
        }

        $s = ScrapSource::firstWhere('source', $source);

        return [
            'has_integration' => (bool) ($settings && $settings->hasCredentials()),
            'seller' => self::TAB_LABELS[$source] ?? $source,
            'last_sync_at' => $s?->last_sync_at?->toIso8601String(),
            'last_sync_count' => $s?->last_sync_count,
            'prev_offer_count' => $s?->prev_offer_count,
            'new_count' => $s?->last_new_count,
            'removed_count' => $s?->last_removed_count,
            'price_up' => $s?->last_price_up,
            'price_down' => $s?->last_price_down,
            'status' => $s?->last_status,
        ];
    }

    /** Meta kanału (kafelki monitoringu) z scrap_sources; etykieta z konfiguracji sklepu. */
    private function shopMeta(string $source): array
    {
        $s = ScrapSource::firstWhere('source', $source);

        return [
            'has_integration' => true, // publiczny scrape — nie wymaga konfiguracji
            'seller' => ShopScrapService::SHOPS[$source]['label'] ?? ($s?->label ?? $source),
            'last_sync_at' => $s?->last_sync_at?->toIso8601String(),
            'last_sync_count' => $s?->last_sync_count,
            'prev_offer_count' => $s?->prev_offer_count,
            'new_count' => $s?->last_new_count,
            'removed_count' => $s?->last_removed_count,
            'price_up' => $s?->last_price_up,
            'price_down' => $s?->last_price_down,
            'status' => $s?->last_status,
        ];
    }

    /** Kurs waluty cennika porównawczego → EUR (gdy cennik w PLN/CZK itp.). EUR/brak cennika → 1.0; obca waluta bez kursu → null. */
    private function pricelistEurRate(?int $pricelistId): ?float
    {
        if (! $pricelistId) {
            return 1.0;
        }
        $cur = strtoupper((string) (Pricelist::find($pricelistId)?->currency ?: 'EUR'));
        if ($cur === 'EUR' || $cur === '') {
            return 1.0;
        }

        return app(CurrencyConverter::class)->toEur($cur);
    }

    /** Kurs waluty oferty danego kanału → EUR (GBP/CHF/HUF/RON → EUR). EUR/brak kursu → 1.0. */
    private function channelEurRate(string $source): float
    {
        $cur = EbayScrapService::isMarket($source)
            ? strtoupper(EbayScrapService::MARKETS[$source]['currency'])
            : strtoupper((string) (ShopScrapService::SHOPS[$source]['currency'] ?? 'EUR'));
        if ($cur === 'EUR') {
            return 1.0;
        }

        return app(CurrencyConverter::class)->toEur($cur) ?? 1.0;
    }

    /** Cena netto w EUR → waluta cennika DOCELOWEGO (netto). Np. EUR → CZK gdy cennik kraju w CZK.
     *  $targetRate = kurs waluty cennika → EUR (1.0 dla EUR). Brak kursu → 0.0 (pozycja pomijana). */
    private function toTargetCurrency(float $eurNet, ?float $targetRate): float
    {
        if ($targetRate === null || $targetRate <= 0) {
            return 0.0;
        }

        return round($eurNet / $targetRate, 2);
    }

    /** Ceny brutto z cennika porównawczego kanału, znormalizowane do EUR: [product_id => brutto EUR]. */
    private function comparePrices(?ScrapSource $cfg): array
    {
        if (! $cfg || ! $cfg->compare_pricelist_id) {
            return [];
        }
        $rate = $this->pricelistEurRate($cfg->compare_pricelist_id);
        if ($rate === null) {
            return []; // cennik w obcej walucie, brak kursu → nie pokazuj zamiast błędnych liczb
        }
        $vat = (float) ($cfg->compare_vat ?? 0);

        return PricelistProduct::where('pricelist_id', $cfg->compare_pricelist_id)
            ->whereNotNull('price')
            ->pluck('price', 'product_id')
            ->map(fn ($net) => round((float) $net * (1 + $vat / 100) * $rate, 2)) // netto → brutto → EUR (cennik PLN/CZK)
            ->toArray();
    }

    /** Ceny NETTO z cennika porównawczego kanału, znormalizowane do EUR: [product_id => netto EUR] (bez ×VAT). */
    private function comparePricesNet(?ScrapSource $cfg): array
    {
        if (! $cfg || ! $cfg->compare_pricelist_id) {
            return [];
        }
        $rate = $this->pricelistEurRate($cfg->compare_pricelist_id);
        if ($rate === null) {
            return [];
        }

        return PricelistProduct::where('pricelist_id', $cfg->compare_pricelist_id)
            ->whereNotNull('price')
            ->pluck('price', 'product_id')
            ->map(fn ($net) => round((float) $net * $rate, 2)) // netto → EUR
            ->toArray();
    }

    /** „Utwórz cennik" — nowy cennik (pojawi się w module Cenniki). */
    public function createPricelist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:3'],
        ]);

        $pricelist = Pricelist::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']) . '-' . now()->timestamp,
            'currency' => $data['currency'] ?? 'EUR',
        ]);

        return response()->json(['ok' => true, 'pricelist' => ['id' => $pricelist->id, 'name' => $pricelist->name]]);
    }

    /** „Dodaj cennik do porównania" — zapis wybranego cennika + VAT (netto→brutto). */
    public function setCompare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'pricelist_id' => ['nullable', 'integer', 'exists:pricelists,id'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->channelConfig($data['source'])->update([
            'compare_pricelist_id' => $data['pricelist_id'] ?? null,
            'compare_vat' => $data['vat'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    /** Wybór cennika docelowego (gdzie trafiają zatwierdzone ceny). */
    public function setTarget(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'target_pricelist_id' => ['nullable', 'integer', 'exists:pricelists,id'],
        ]);
        $this->channelConfig($data['source'])->update(['target_pricelist_id' => $data['target_pricelist_id'] ?? null]);

        return response()->json(['ok' => true]);
    }

    /** „Zatwierdź zaznaczone" — ceny eBay zaznaczonych ofert → cennik docelowy (po product_id). */
    public function approve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'scrap_ids' => ['required', 'array', 'min:1'],
            'scrap_ids.*' => ['integer'],
            'target_pricelist_id' => ['required', 'integer', 'exists:pricelists,id'],
        ]);

        $cfg = $this->channelConfig($data['source']);
        $vat = (float) ($cfg->compare_vat ?? 0);
        $compareNet = $this->comparePricesNet($cfg);
        $fx = app(CurrencyConverter::class);
        $targetRate = $this->pricelistEurRate($data['target_pricelist_id']); // waluta cennika docelowego → EUR

        $scraps = ScrapProduct::whereIn('id', $data['scrap_ids'])
            ->whereNotNull('product_id')
            ->get(['id', 'product_id', 'price', 'individual_price', 'currency']);

        // Do cennika idzie NIŻSZA z (oferta/indywidualna ↔ cennik porównawczy), NETTO, w WALUCIE cennika docelowego.
        $rows = $scraps->map(fn (ScrapProduct $s) => [
            'pricelist_id' => $data['target_pricelist_id'],
            'product_id' => $s->product_id,
            'price' => $this->toTargetCurrency($this->targetNetPrice($s, $vat, $compareNet, $fx), $targetRate),
        ])->filter(fn (array $r) => $r['price'] > 0)->values()->toArray();

        if (! empty($rows)) {
            PricelistProduct::upsert($rows, ['pricelist_id', 'product_id'], ['price']);
        }

        $cfg->update(['target_pricelist_id' => $data['target_pricelist_id']]);

        return response()->json(['ok' => true, 'count' => count($rows)]);
    }

    /** „Aktualizuj cennik" — wszystkie zmapowane oferty źródła (cena>0) → cennik docelowy (hurtem).
     *  Gdy kilka ofert wskazuje ten sam nasz produkt, bierze najniższą cenę. */
    public function updateAll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'target_pricelist_id' => ['required', 'integer', 'exists:pricelists,id'],
        ]);

        $cfg = $this->channelConfig($data['source']);
        $vat = (float) ($cfg->compare_vat ?? 0);
        $compareNet = $this->comparePricesNet($cfg);
        $fx = app(CurrencyConverter::class);
        $targetRate = $this->pricelistEurRate($data['target_pricelist_id']); // waluta cennika docelowego → EUR

        // Do cennika idzie NIŻSZA z (oferta/indywidualna ↔ cennik porównawczy), NETTO, w WALUCIE cennika docelowego.
        // Pomijamy WYKLUCZONE (excluded = true) → w cenniku zostaje ich oryginalna cena.
        $rows = ScrapProduct::where('source', $data['source'])
            ->whereNotNull('product_id')
            ->where('excluded', false)
            ->get(['product_id', 'price', 'individual_price', 'currency'])
            ->map(fn (ScrapProduct $s) => [
                'product_id' => $s->product_id,
                'price' => $this->toTargetCurrency($this->targetNetPrice($s, $vat, $compareNet, $fx), $targetRate),
            ])
            ->filter(fn (array $r) => $r['price'] > 0)
            ->groupBy('product_id')
            ->map(fn ($group, $pid) => [
                'pricelist_id' => $data['target_pricelist_id'],
                'product_id' => (int) $pid,
                'price' => (float) $group->min('price'),
            ])
            ->values()
            ->toArray();

        if (! empty($rows)) {
            PricelistProduct::upsert($rows, ['pricelist_id', 'product_id'], ['price']);
        }

        $cfg->update(['target_pricelist_id' => $data['target_pricelist_id']]);

        return response()->json(['ok' => true, 'count' => count($rows)]);
    }
}
