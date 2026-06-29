<?php

namespace App\Http\Controllers\Admin;

use App\Models\Connect\BaseSettings;
use App\Models\Connect\Order;
use App\Models\OdysseyCostMonth;
use App\Models\OdysseyCostOrderEntry;
use App\Models\OdysseyCostPayment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OdysseyCostController extends Controller
{
    private const BASE_LABEL_LIKE = 'Odyssey%';

    private function odysseyBaseId(): ?int
    {
        return BaseSettings::where('label', 'like', self::BASE_LABEL_LIKE)->value('id');
    }

    public function index(): Response
    {
        $months = OdysseyCostMonth::query()
            ->withCount('entries')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(function (OdysseyCostMonth $m) {
                $totals = $this->totalsForMonth($m);
                return [
                    'id'             => $m->id,
                    'label'          => $m->label,
                    'year'           => $m->year,
                    'month'          => $m->month,
                    'entries_count'  => $m->entries_count,
                    'total_goods'    => $totals['goods'],
                    'total_shipping' => $totals['shipping'],
                    'total_sum'      => $totals['sum'],
                    'total_paid'     => $totals['paid'],
                    'balance_due'    => $totals['balance'],
                ];
            });

        return Inertia::render('OdysseyCost/Index', [
            'months' => $months,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'year'  => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'notes' => 'nullable|string',
        ]);

        if (OdysseyCostMonth::where('year', $validated['year'])->where('month', $validated['month'])->exists()) {
            return back()->withErrors(['month' => 'Taki miesiąc już istnieje.']);
        }

        $month = OdysseyCostMonth::create([
            'year'  => $validated['year'],
            'month' => $validated['month'],
            'label' => OdysseyCostMonth::buildLabel($validated['year'], $validated['month']),
            'notes' => $validated['notes'] ?? null,
        ]);

        $this->syncEntriesForMonth($month);

        return redirect()->route('crafter.odyssey-cost.show', $month->id)
            ->with('message', 'Miesiąc utworzony.');
    }

    public function show(OdysseyCostMonth $odysseyCostMonth): Response
    {
        $this->syncEntriesForMonth($odysseyCostMonth);

        $entries = OdysseyCostOrderEntry::with(['order.products', 'order.baseSettings'])
            ->where('odyssey_cost_month_id', $odysseyCostMonth->id)
            ->get()
            ->map(function (OdysseyCostOrderEntry $e) {
                $order = $e->order;
                $items = $order
                    ? $order->products->map(fn ($p) => trim(($p->name ?? '—') . ' ×' . (int) $p->quantity))->implode(', ')
                    : '';

                return [
                    'id'                   => $e->id,
                    'order_id'             => $e->order_id,
                    'baselinker_order_id'  => $order?->baselinker_order_id,
                    'order_date'           => $order?->date_add?->toDateString(),
                    'items_label'          => $items,
                    'tracking_number'      => $order?->delivery_package_nr,
                    'cost_goods'           => (float) $e->cost_goods,
                    'cost_shipping'        => (float) $e->cost_shipping,
                    'total'                => (float) $e->cost_goods + (float) $e->cost_shipping,
                ];
            })
            ->sortBy('baselinker_order_id')
            ->values();

        $payments = $odysseyCostMonth->payments->map(fn ($p) => [
            'id'             => $p->id,
            'paid_at'        => $p->paid_at?->toDateString(),
            'amount'         => (float) $p->amount,
            'invoice_number' => $p->invoice_number,
        ]);

        return Inertia::render('OdysseyCost/Show', [
            'month'    => $odysseyCostMonth->only(['id', 'label', 'year', 'month', 'notes']),
            'entries'  => $entries,
            'payments' => $payments,
        ]);
    }

    public function destroy(OdysseyCostMonth $odysseyCostMonth): RedirectResponse
    {
        $odysseyCostMonth->delete();

        return redirect()->route('crafter.odyssey-cost.index')
            ->with('message', 'Miesiąc usunięty.');
    }

    public function refresh(OdysseyCostMonth $odysseyCostMonth): RedirectResponse
    {
        $this->syncEntriesForMonth($odysseyCostMonth);

        return back()->with('message', 'Zamówienia odświeżone.');
    }

    public function updateEntry(Request $request, OdysseyCostOrderEntry $entry): JsonResponse
    {
        $validated = $request->validate([
            'cost_goods'    => 'sometimes|numeric|min:0',
            'cost_shipping' => 'sometimes|numeric|min:0',
        ]);

        $entry->update($validated);

        return response()->json(['entry' => $entry->fresh()]);
    }

    public function storePayment(Request $request, OdysseyCostMonth $odysseyCostMonth): JsonResponse
    {
        $validated = $request->validate([
            'paid_at'        => 'required|date',
            'amount'         => 'required|numeric|min:0',
            'invoice_number' => 'nullable|string|max:64',
        ]);

        $payment = $odysseyCostMonth->payments()->create($validated);

        return response()->json(['payment' => $payment], 201);
    }

    public function updatePayment(Request $request, OdysseyCostPayment $payment): JsonResponse
    {
        $validated = $request->validate([
            'paid_at'        => 'sometimes|date',
            'amount'         => 'sometimes|numeric|min:0',
            'invoice_number' => 'sometimes|nullable|string|max:64',
        ]);

        $payment->update($validated);

        return response()->json(['payment' => $payment->fresh()]);
    }

    public function destroyPayment(OdysseyCostPayment $payment): JsonResponse
    {
        $payment->delete();

        return response()->json(['ok' => true]);
    }

    private function syncEntriesForMonth(OdysseyCostMonth $month): void
    {
        $baseId = $this->odysseyBaseId();
        if (!$baseId) {
            return;
        }

        $start = Carbon::create($month->year, $month->month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $orderIds = Order::where('base_settings_id', $baseId)
            ->whereBetween('date_add', [$start, $end])
            ->pluck('id');

        $existing = OdysseyCostOrderEntry::where('odyssey_cost_month_id', $month->id)
            ->pluck('order_id')
            ->all();

        $missing = $orderIds->diff($existing);

        foreach ($missing as $orderId) {
            OdysseyCostOrderEntry::create([
                'odyssey_cost_month_id' => $month->id,
                'order_id'              => $orderId,
                'cost_goods'            => 0,
                'cost_shipping'         => 0,
            ]);
        }
    }

    private function totalsForMonth(OdysseyCostMonth $month): array
    {
        $goods = (float) $month->entries()->sum('cost_goods');
        $shipping = (float) $month->entries()->sum('cost_shipping');
        $paid = (float) $month->payments()->sum('amount');

        $sum = $goods + $shipping;
        return [
            'goods'    => $goods,
            'shipping' => $shipping,
            'sum'      => $sum,
            'paid'     => $paid,
            'balance'  => $sum - $paid,
        ];
    }
}
