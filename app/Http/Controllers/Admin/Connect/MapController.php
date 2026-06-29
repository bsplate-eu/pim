<?php

namespace App\Http\Controllers\Admin\Connect;

use App\Http\Controllers\Admin\Controller;
use App\Models\Connect\BaseSettings;
use App\Models\Connect\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function index(): Response
    {
        $countries = Order::query()
            ->whereNotNull('delivery_country_code')
            ->where('delivery_country_code', '!=', '')
            ->distinct()
            ->orderBy('delivery_country_code')
            ->pluck('delivery_country_code')
            ->values();

        $sources = Order::query()
            ->whereNotNull('order_source')
            ->where('order_source', '!=', '')
            ->distinct()
            ->orderBy('order_source')
            ->pluck('order_source')
            ->values();

        $bases = BaseSettings::query()
            ->orderBy('label')
            ->get(['id', 'label']);

        return Inertia::render('Connect/Map/Index', [
            'countries' => $countries,
            'sources'   => $sources,
            'bases'     => $bases,
        ]);
    }

    public function points(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date'],
            'countries'   => ['nullable', 'array'],
            'countries.*' => ['string', 'size:2'],
            'sources'     => ['nullable', 'array'],
            'sources.*'   => ['string'],
            'bases'       => ['nullable', 'array'],
            'bases.*'     => ['integer'],
        ]);

        $from      = $validated['date_from'] ?? null;
        $to        = $validated['date_to'] ?? null;
        $countries = $validated['countries'] ?? [];
        $sources   = $validated['sources'] ?? [];
        $bases     = $validated['bases'] ?? [];

        $applyFilters = function ($query) use ($from, $to, $countries, $sources, $bases) {
            if ($from) {
                $query->where('orders.date_add', '>=', $from . ' 00:00:00');
            }
            if ($to) {
                $query->where('orders.date_add', '<=', $to . ' 23:59:59');
            }
            if (! empty($countries)) {
                $query->whereIn('orders.delivery_country_code', $countries);
            }
            if (! empty($sources)) {
                $query->whereIn('orders.order_source', $sources);
            }
            if (! empty($bases)) {
                $query->whereIn('orders.base_settings_id', $bases);
            }
        };

        // Zamówienia zmapowane do geo_postal_codes (jeden wiersz per postcode+waluta)
        $query = DB::table('orders')
            ->join('geo_postal_codes as gpc', function ($join) {
                $join->on('gpc.country_code', '=', 'orders.delivery_country_code')
                    ->on('gpc.postal_code', '=', 'orders.delivery_postcode');
            })
            ->select([
                'orders.delivery_country_code as country_code',
                'orders.delivery_postcode as postal_code',
                'gpc.place_name',
                'gpc.latitude',
                'gpc.longitude',
                'orders.currency',
                DB::raw('COUNT(*) AS orders_count'),
                DB::raw('SUM(orders.total_amount) AS total_amount'),
            ])
            ->groupBy(
                'orders.delivery_country_code',
                'orders.delivery_postcode',
                'gpc.place_name',
                'gpc.latitude',
                'gpc.longitude',
                'orders.currency'
            );

        $applyFilters($query);
        $rows = $query->get();

        $points = [];
        foreach ($rows as $row) {
            $key = $row->country_code . '|' . $row->postal_code;
            if (! isset($points[$key])) {
                $points[$key] = [
                    'country_code' => $row->country_code,
                    'postal_code'  => $row->postal_code,
                    'place_name'   => $row->place_name,
                    'lat'          => (float) $row->latitude,
                    'lng'          => (float) $row->longitude,
                    'orders_count' => 0,
                    'values'       => [],
                ];
            }
            $points[$key]['orders_count'] += (int) $row->orders_count;
            $points[$key]['values'][]     = [
                'currency' => $row->currency,
                'total'    => round((float) $row->total_amount, 2),
            ];
        }

        // Zamówienia bez geolokalizacji (brak matchingu postcode w geo_postal_codes)
        $unmappedQuery = DB::table('orders')
            ->leftJoin('geo_postal_codes as gpc', function ($join) {
                $join->on('gpc.country_code', '=', 'orders.delivery_country_code')
                    ->on('gpc.postal_code', '=', 'orders.delivery_postcode');
            })
            ->whereNull('gpc.id');

        $applyFilters($unmappedQuery);
        $unmappedCount = (int) $unmappedQuery->count('orders.id');

        return response()->json([
            'points'         => array_values($points),
            'unmapped_count' => $unmappedCount,
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'string', 'size:2'],
            'postal_code'  => ['required', 'string', 'max:30'],
            'date_from'    => ['nullable', 'date'],
            'date_to'      => ['nullable', 'date'],
            'countries'    => ['nullable', 'array'],
            'sources'      => ['nullable', 'array'],
            'bases'        => ['nullable', 'array'],
        ]);

        $query = Order::query()
            ->where('delivery_country_code', $validated['country_code'])
            ->where('delivery_postcode', $validated['postal_code']);

        if (! empty($validated['date_from'])) {
            $query->where('date_add', '>=', $validated['date_from'] . ' 00:00:00');
        }
        if (! empty($validated['date_to'])) {
            $query->where('date_add', '<=', $validated['date_to'] . ' 23:59:59');
        }
        if (! empty($validated['sources'])) {
            $query->whereIn('order_source', $validated['sources']);
        }
        if (! empty($validated['bases'])) {
            $query->whereIn('base_settings_id', $validated['bases']);
        }

        $orders = $query
            ->orderByDesc('date_add')
            ->limit(100)
            ->get([
                'id',
                'baselinker_order_id',
                'external_order_id',
                'order_source',
                'delivery_fullname',
                'delivery_city',
                'date_add',
                'total_amount',
                'currency',
            ])
            ->map(fn (Order $o) => [
                'id'                  => $o->id,
                'baselinker_order_id' => $o->baselinker_order_id,
                'external_order_id'   => $o->external_order_id,
                'order_source'        => $o->order_source,
                'delivery_fullname'   => $o->delivery_fullname,
                'delivery_city'       => $o->delivery_city,
                'date_add'            => $o->date_add?->toIso8601String(),
                'total_amount'        => (float) $o->total_amount,
                'currency'            => $o->currency,
            ]);

        return response()->json(['orders' => $orders]);
    }
}
