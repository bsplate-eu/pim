<?php

namespace App\Http\Controllers\Admin\Connect;

use App\Http\Controllers\Admin\Controller;
use App\Models\Connect\BaseSettings;
use App\Models\Connect\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $customersQuery = QueryBuilder::for(Customer::class)
            ->allowedFilters([
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('full_name', 'like', "%{$value}%")
                            ->orWhere('email', 'like', "%{$value}%")
                            ->orWhere('phone', 'like', "%{$value}%")
                            ->orWhere('company', 'like', "%{$value}%")
                            ->orWhere('nip', 'like', "%{$value}%")
                            ->orWhere('city', 'like', "%{$value}%");
                    });
                }),
                AllowedFilter::exact('country_code'),
                AllowedFilter::exact('primary_source'),
                AllowedFilter::callback('base_settings_id', function ($query, $value) {
                    $query->whereHas('orders', fn ($q) => $q->where('base_settings_id', (int) $value));
                }),
            ])
            ->defaultSort('-last_order_at')
            ->allowedSorts('last_order_at', 'first_order_at', 'orders_count', 'full_name', 'country_code');

        $customers = $customersQuery
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        $customers->getCollection()->transform(fn (Customer $c) => [
            'id' => $c->id,
            'full_name' => $c->full_name,
            'first_name' => $c->first_name,
            'last_name' => $c->last_name,
            'display_name' => $c->display_name,
            'email' => $c->email,
            'phone' => $c->phone,
            'company' => $c->company,
            'city' => $c->city,
            'postcode' => $c->postcode,
            'country' => $c->country,
            'country_code' => $c->country_code,
            'primary_source' => $c->primary_source,
            'orders_count' => $c->orders_count,
            'last_order_at' => $c->last_order_at?->toIso8601String(),
        ]);

        return Inertia::render('Connect/Customers/Index', [
            'customers' => $customers,
            'countries' => Customer::query()
                ->whereNotNull('country_code')
                ->select('country_code', 'country')
                ->distinct()
                ->orderBy('country_code')
                ->get(),
            'sources' => Customer::query()
                ->whereNotNull('primary_source')
                ->distinct()
                ->pluck('primary_source'),
            'bases' => BaseSettings::query()
                ->orderBy('id')
                ->get(['id', 'label']),
        ]);
    }

    public function show(Customer $customer): Response
    {
        $customer->load(['orders' => function ($q) {
            $q->orderByDesc('date_add')->with('products:id,order_id,name,sku,quantity,price_brutto');
        }]);

        $orders = $customer->orders->map(fn ($o) => [
            'id' => $o->id,
            'baselinker_order_id' => $o->baselinker_order_id,
            'order_source' => $o->order_source,
            'order_status_id' => $o->order_status_id,
            'date_add' => $o->date_add?->toIso8601String(),
            'total_amount' => (float) $o->total_amount,
            'payment_done' => (float) $o->payment_done,
            'currency' => $o->currency,
            'products_count' => $o->products->count(),
        ]);

        // Agregaty po walucie
        $spendingByCurrency = $customer->orders
            ->groupBy(fn ($o) => $o->currency ?? 'PLN')
            ->map(fn ($group) => [
                'currency' => $group->first()->currency ?? 'PLN',
                'total' => (float) $group->sum('total_amount'),
                'orders' => $group->count(),
            ])
            ->values();

        return Inertia::render('Connect/Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'full_name' => $customer->full_name,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'display_name' => $customer->display_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'phone_raw' => $customer->phone_raw,
                'user_login' => $customer->user_login,
                'crm_client_id' => $customer->crm_client_id,
                'company' => $customer->company,
                'nip' => $customer->nip,
                'address' => $customer->address,
                'postcode' => $customer->postcode,
                'city' => $customer->city,
                'state' => $customer->state,
                'country' => $customer->country,
                'country_code' => $customer->country_code,
                'primary_source' => $customer->primary_source,
                'sources' => $customer->sources ?? [],
                'orders_count' => $customer->orders_count,
                'first_order_at' => $customer->first_order_at?->toIso8601String(),
                'last_order_at' => $customer->last_order_at?->toIso8601String(),
                'created_at' => $customer->created_at?->toIso8601String(),
            ],
            'orders' => $orders,
            'spendingByCurrency' => $spendingByCurrency,
        ]);
    }
}
