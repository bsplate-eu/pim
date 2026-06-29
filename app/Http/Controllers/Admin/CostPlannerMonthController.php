<?php

namespace App\Http\Controllers\Admin;

use App\Models\BankStatementItem;
use App\Models\CostPlannerItem;
use App\Models\CostPlannerMonth;
use App\Models\CostPlannerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CostPlannerMonthController extends Controller
{
    public function index(): Response
    {
        $months = CostPlannerMonth::query()
            ->withCount('items')
            ->withSum('items as total_amount', 'amount')
            ->withSum(['items as total_unpaid' => function ($q) {
                $q->where('status', '!=', 'Zapłacone');
            }], 'amount')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return Inertia::render('CostPlanner/Index', [
            'months' => $months,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'year'          => 'required|integer|min:2000|max:2100',
            'month'         => 'required|integer|min:1|max:12',
            'notes'         => 'nullable|string',
            'clone_from_id' => 'nullable|integer|exists:cost_planner_months,id',
        ]);

        $exists = CostPlannerMonth::where('year', $validated['year'])
            ->where('month', $validated['month'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['month' => 'Taki miesiąc już istnieje.']);
        }

        $month = CostPlannerMonth::create([
            'year'  => $validated['year'],
            'month' => $validated['month'],
            'label' => CostPlannerMonth::buildLabel($validated['year'], $validated['month']),
            'notes' => $validated['notes'] ?? null,
        ]);

        if (!empty($validated['clone_from_id'])) {
            $source = CostPlannerMonth::with('items')->find($validated['clone_from_id']);
            if ($source) {
                foreach ($source->items as $item) {
                    $month->items()->create([
                        'name'           => $item->name,
                        'amount'         => $item->amount,
                        'status'         => 'Do zapłaty',
                        'due_date'       => null,
                        'category'       => $item->category,
                        'type'           => $item->type,
                        'invoice_number' => null,
                        'currency'       => $item->currency,
                        'position'       => $item->position,
                    ]);
                }
            }
        }

        return redirect()->route('crafter.cost-planner.show', $month->id)
            ->with('message', 'Miesiąc utworzony.');
    }

    public function show(CostPlannerMonth $costPlannerMonth): Response
    {
        $costPlannerMonth->load('items');

        // ID kosztów rozliczonych z wyciągiem bankowym.
        $reconciledIds = BankStatementItem::whereNotNull('matched_id')
            ->where('matched_type', CostPlannerItem::class)
            ->whereIn('matched_id', $costPlannerMonth->items->pluck('id'))
            ->pluck('matched_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        return Inertia::render('CostPlanner/Show', [
            'month'          => $costPlannerMonth,
            'items'          => $costPlannerMonth->items,
            'settings'       => CostPlannerSettings::instance()->toPayload(),
            'reconciledIds'  => $reconciledIds,
        ]);
    }

    public function update(Request $request, CostPlannerMonth $costPlannerMonth): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $costPlannerMonth->update($validated);

        return back()->with('message', 'Zaktualizowano.');
    }

    public function destroy(CostPlannerMonth $costPlannerMonth): RedirectResponse
    {
        $costPlannerMonth->delete();

        return redirect()->route('crafter.cost-planner.index')
            ->with('message', 'Miesiąc usunięty.');
    }
}
