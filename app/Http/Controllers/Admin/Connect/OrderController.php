<?php

namespace App\Http\Controllers\Admin\Connect;

use App\Http\Controllers\Admin\Controller;
use App\Models\Connect\BaseSettings;
use App\Models\Connect\Invoice;
use App\Models\Connect\Order;
use App\Models\Connect\OrderStatus;
use App\Models\Product;
use App\Services\BaseLinker\BaseLinkerInvoiceSyncService;
use App\Services\BaseLinker\BaseLinkerOrderSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $statusesById = OrderStatus::pluck('name', 'baselinker_status_id')->toArray();
        $statusColorsById = OrderStatus::pluck('color', 'baselinker_status_id')->toArray();
        $baseLabelsById = BaseSettings::pluck('label', 'id')->toArray();

        $ordersQuery = QueryBuilder::for(Order::class)
            ->allowedFilters([
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('baselinker_order_id', 'like', "%{$value}%")
                            ->orWhere('external_order_id', 'like', "%{$value}%")
                            ->orWhere('email', 'like', "%{$value}%")
                            ->orWhere('delivery_fullname', 'like', "%{$value}%")
                            ->orWhere('invoice_nip', 'like', "%{$value}%")
                            ->orWhere('phone', 'like', "%{$value}%");
                    });
                }),
                AllowedFilter::exact('order_status_id'),
                AllowedFilter::exact('order_source'),
                AllowedFilter::exact('base_settings_id'),
            ])
            ->defaultSort('-date_add')
            ->allowedSorts('date_add', 'date_confirmed', 'total_amount', 'baselinker_order_id');

        $orders = $ordersQuery
            ->with('products:id,order_id,name,sku,ean,quantity,price_brutto')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        // Miniatury produktów — batch dla całej strony (jeden zapytanie, bez N+1)
        $allProducts = $orders->getCollection()->flatMap(fn (Order $o) => $o->products);
        $thumbs = $this->resolveProductThumbnails($allProducts);

        // Wzbogać każdy wiersz o status name/color
        $orders->getCollection()->transform(function (Order $order) use ($statusesById, $statusColorsById, $baseLabelsById, $thumbs) {
            $sid = (int) $order->order_status_id;
            return [
                'id' => $order->id,
                'baselinker_order_id' => $order->baselinker_order_id,
                'external_order_id' => $order->external_order_id,
                'order_source' => $order->order_source,
                'order_source_info' => $order->order_source_info,
                'order_status_id' => $sid,
                'status_name' => $statusesById[$sid] ?? null,
                'status_color' => $statusColorsById[$sid] ?? null,
                'base_settings_id' => $order->base_settings_id,
                'base_label' => $order->base_settings_id ? ($baseLabelsById[$order->base_settings_id] ?? null) : null,
                'date_add' => $order->date_add?->toIso8601String(),
                'date_confirmed' => $order->date_confirmed?->toIso8601String(),
                'date_in_status' => $order->date_in_status?->toIso8601String(),
                'email' => $order->email,
                'phone' => $order->phone,
                'delivery_fullname' => $order->delivery_fullname,
                'delivery_country' => $order->delivery_country,
                'delivery_country_code' => $order->delivery_country_code,
                'delivery_city' => $order->delivery_city,
                'delivery_postcode' => $order->delivery_postcode,
                'delivery_method' => $order->delivery_method,
                'payment_method' => $order->payment_method,
                'payment_done' => (float) $order->payment_done,
                'total_amount' => (float) $order->total_amount,
                'currency' => $order->currency,
                'confirmed' => (bool) $order->confirmed,
                'pick_state' => (int) $order->pick_state,
                'pack_state' => (int) $order->pack_state,
                'want_invoice' => (bool) $order->want_invoice,
                'star' => (int) $order->star,
                'user_comments' => $order->user_comments,
                'admin_comments' => $order->admin_comments,
                'extra_field_1' => $order->extra_field_1,
                'invoice_company' => $order->invoice_company,
                'invoice_fullname' => $order->invoice_fullname,
                'products' => $order->products->map(fn ($p) => [
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'quantity' => $p->quantity,
                    'price_brutto' => (float) $p->price_brutto,
                    'thumbnail' => $thumbs[$p->id] ?? null,
                ]),
            ];
        });

        return Inertia::render('Connect/Orders/Index', [
            'orders' => $orders,
            'statuses' => OrderStatus::orderBy('baselinker_status_id')
                ->get(['baselinker_status_id as id', 'name', 'color']),
            'sources' => Order::query()
                ->select('order_source')
                ->whereNotNull('order_source')
                ->distinct()
                ->pluck('order_source'),
            'bases' => BaseSettings::query()
                ->orderBy('id')
                ->get(['id', 'label']),
        ]);
    }

    public function show(Order $order): Response
    {
        $order->load(['products', 'invoices']);

        $status = OrderStatus::where('baselinker_status_id', $order->order_status_id)->first();

        // Nawigacja prev/next (po dacie dodania)
        $prevId = Order::where('date_add', '<', $order->date_add)
            ->orderByDesc('date_add')
            ->value('id');
        $nextId = Order::where('date_add', '>', $order->date_add)
            ->orderBy('date_add')
            ->value('id');

        return Inertia::render('Connect/Orders/Show', [
            'order' => $this->serializeOrder($order, $status),
            'invoices' => $this->serializeInvoices($order->invoices),
            'navigation' => [
                'prev_id' => $prevId,
                'next_id' => $nextId,
            ],
        ]);
    }

    public function syncSingle(Order $order): JsonResponse
    {
        $settings = $order->base_settings_id
            ? BaseSettings::find($order->base_settings_id)
            : BaseSettings::orderBy('id')->first();

        if (! $settings || ! $settings->hasApiKey()) {
            return response()->json([
                'ok' => false,
                'message' => 'Brak klucza API BaseLinker dla tego zamówienia.',
            ], 422);
        }

        try {
            $service = BaseLinkerOrderSyncService::fromSettings($settings);
            $updated = $service->syncSingleOrder((int) $order->baselinker_order_id);

            if (! $updated) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Zamówienie nie zostało znalezione w BaseLinker.',
                ], 404);
            }

            // Odśwież faktury dla tego zamówienia (best-effort)
            $invoicesFetched = 0;
            try {
                $invoiceService = BaseLinkerInvoiceSyncService::fromSettings($settings);
                $invoicesFetched = count($invoiceService->syncInvoicesForOrder((int) $order->baselinker_order_id));
            } catch (\Throwable $ie) {
                \Log::warning('Invoice refresh failed for order ' . $order->baselinker_order_id . ': ' . $ie->getMessage());
            }

            return response()->json([
                'ok' => true,
                'message' => 'Zamówienie odświeżone.' . ($invoicesFetched ? " Faktury: {$invoicesFetched}." : ''),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function serializeOrder(Order $order, ?OrderStatus $status): array
    {
        $thumbs = $this->resolveProductThumbnails($order->products);

        return [
            'id' => $order->id,
            'baselinker_order_id' => $order->baselinker_order_id,
            'shop_order_id' => $order->shop_order_id,
            'external_order_id' => $order->external_order_id,
            'order_source' => $order->order_source,
            'order_source_id' => $order->order_source_id,
            'order_source_info' => $order->order_source_info,

            'order_status_id' => $order->order_status_id,
            'status_name' => $status?->name,
            'status_color' => $status?->color,

            'date_add' => $order->date_add?->toIso8601String(),
            'date_confirmed' => $order->date_confirmed?->toIso8601String(),
            'date_in_status' => $order->date_in_status?->toIso8601String(),
            'confirmed' => (bool) $order->confirmed,

            'email' => $order->email,
            'phone' => $order->phone,
            'user_login' => $order->user_login,
            'user_comments' => $order->user_comments,
            'admin_comments' => $order->admin_comments,

            'currency' => $order->currency,
            'payment_method' => $order->payment_method,
            'payment_method_cod' => (bool) $order->payment_method_cod,
            'payment_done' => (float) $order->payment_done,
            'total_amount' => (float) $order->total_amount,
            'balance_due' => $order->balance_due,

            'delivery' => [
                'method_id' => $order->delivery_method_id,
                'method' => $order->delivery_method,
                'price' => (float) $order->delivery_price,
                'package_module' => $order->delivery_package_module,
                'package_nr' => $order->delivery_package_nr,
                'fullname' => $order->delivery_fullname,
                'company' => $order->delivery_company,
                'address' => $order->delivery_address,
                'postcode' => $order->delivery_postcode,
                'city' => $order->delivery_city,
                'state' => $order->delivery_state,
                'country' => $order->delivery_country,
                'country_code' => $order->delivery_country_code,
                'point_id' => $order->delivery_point_id,
                'point_name' => $order->delivery_point_name,
                'point_address' => $order->delivery_point_address,
                'point_postcode' => $order->delivery_point_postcode,
                'point_city' => $order->delivery_point_city,
            ],

            'invoice' => [
                'want_invoice' => (bool) $order->want_invoice,
                'fullname' => $order->invoice_fullname,
                'company' => $order->invoice_company,
                'nip' => $order->invoice_nip,
                'address' => $order->invoice_address,
                'postcode' => $order->invoice_postcode,
                'city' => $order->invoice_city,
                'state' => $order->invoice_state,
                'country' => $order->invoice_country,
                'country_code' => $order->invoice_country_code,
            ],

            'extra_field_1' => $order->extra_field_1,
            'extra_field_2' => $order->extra_field_2,
            'custom_extra_fields' => $order->custom_extra_fields,
            'pick_state' => (int) $order->pick_state,
            'pack_state' => (int) $order->pack_state,
            'star' => (int) $order->star,
            'commission' => $order->commission,
            'order_page' => $order->order_page,

            'products' => $order->products->map(fn ($p) => [
                'id' => $p->id,
                'baselinker_order_product_id' => $p->baselinker_order_product_id,
                'name' => $p->name,
                'sku' => $p->sku,
                'ean' => $p->ean,
                'attributes' => $p->attributes,
                'storage' => $p->storage,
                'location' => $p->location,
                'quantity' => (int) $p->quantity,
                'price_brutto' => (float) $p->price_brutto,
                'tax_rate' => (float) $p->tax_rate,
                'weight' => (float) $p->weight,
                'line_total' => $p->line_total,
                'auction_id' => $p->auction_id,
                'thumbnail' => $thumbs[$p->id] ?? null,
            ]),

            'imported_at' => $order->imported_at?->toIso8601String(),
            'updated_from_api_at' => $order->updated_from_api_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function serializeInvoices($invoices): array
    {
        return $invoices
            ->sortBy([['type', 'asc'], ['issue_date', 'asc']])
            ->values()
            ->map(fn (Invoice $i) => [
                'id' => $i->id,
                'baselinker_invoice_id' => $i->baselinker_invoice_id,
                'type' => $i->type,
                'series_name' => $i->series_name,
                'nr' => $i->nr,
                'nr_full' => $i->display_number,
                'corrected_invoice_id' => $i->corrected_invoice_id,
                'issue_date' => $i->issue_date?->toDateString(),
                'sell_date' => $i->sell_date?->toDateString(),
                'payment_date' => $i->payment_date?->toDateString(),
                'total_netto' => (float) $i->total_netto,
                'total_brutto' => (float) $i->total_brutto,
                'currency' => $i->currency,
            ])
            ->toArray();
    }

    /**
     * Dla pozycji zamówienia dociąga miniaturę z lokalnego katalogu PIM.
     * Dopasowanie: EAN (precyzyjne) → product_code (= BL sku). Bierze pierwsze zdjęcie
     * z kolekcji "images" (konwersja "preview").
     *
     * @return array<int,?string>  [order_product_id => url|null]
     */
    private function resolveProductThumbnails($orderProducts): array
    {
        $skus = $orderProducts->pluck('sku')
            ->filter(fn ($v) => $v !== null && $v !== '')->unique()->values()->all();
        $eans = $orderProducts->pluck('ean')
            ->filter(fn ($v) => $v !== null && $v !== '')->unique()->values()->all();

        if (empty($skus) && empty($eans)) {
            return [];
        }

        $products = Product::query()
            ->where(function ($q) use ($skus, $eans) {
                if (! empty($skus)) {
                    $q->whereIn('product_code', $skus);
                }
                if (! empty($eans)) {
                    $q->orWhereIn('ean', $eans);
                }
            })
            ->with('media')
            ->get();

        $byCode = [];
        $byEan = [];
        foreach ($products as $p) {
            $media = $p->getFirstMedia('images');
            if (! $media) {
                continue;
            }
            // Oryginał (nieprzycięty) — konwersja "preview" jest fit('crop') i ucina obraz.
            $url = $media->getUrl();
            if ($p->product_code && ! isset($byCode[$p->product_code])) {
                $byCode[$p->product_code] = $url;
            }
            if ($p->ean && ! isset($byEan[$p->ean])) {
                $byEan[$p->ean] = $url;
            }
        }

        $result = [];
        foreach ($orderProducts as $op) {
            $url = null;
            if ($op->ean && isset($byEan[$op->ean])) {
                $url = $byEan[$op->ean];
            } elseif ($op->sku && isset($byCode[$op->sku])) {
                $url = $byCode[$op->sku];
            }
            $result[$op->id] = $url;
        }

        return $result;
    }
}
