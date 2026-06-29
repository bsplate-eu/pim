<?php

namespace App\Http\Controllers\Admin;

use App\Models\BankStatementItem;
use App\Models\BankStatementMonth;
use App\Models\CostPlannerMonth;
use App\Services\BankStatement\ParserFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankStatementMonthController extends Controller
{
    public function index(): Response
    {
        $months = BankStatementMonth::query()
            ->withCount('items')
            ->withCount(['items as matched_count' => function ($q) {
                $q->whereNotNull('matched_id');
            }])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('bank')
            ->get();

        return Inertia::render('BankStatement/Index', [
            'months' => $months,
            'banks'  => BankStatementMonth::BANKS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bank'  => ['required', Rule::in(BankStatementMonth::BANKS)],
            'year'  => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'file'  => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $exists = BankStatementMonth::where('bank', $validated['bank'])
            ->where('year', $validated['year'])
            ->where('month', $validated['month'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['month' => 'Wyciąg dla tego banku i miesiąca już istnieje.']);
        }

        $path = $request->file('file')->store('bank-statements', 'local');
        $fullPath = Storage::disk('local')->path($path);

        try {
            $parser = ParserFactory::make($validated['bank']);
            $rows = $parser->parse($fullPath);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            return back()->withErrors(['file' => 'Błąd parsowania pliku: ' . $e->getMessage()]);
        }

        if (empty($rows)) {
            Storage::disk('local')->delete($path);
            return back()->withErrors(['file' => 'Nie znaleziono pozycji w pliku — sprawdź format.']);
        }

        $month = DB::transaction(function () use ($validated, $path, $request, $rows) {
            $month = BankStatementMonth::create([
                'bank'        => $validated['bank'],
                'year'        => $validated['year'],
                'month'       => $validated['month'],
                'label'       => BankStatementMonth::buildLabel($validated['bank'], $validated['year'], $validated['month']),
                'file_path'   => $path,
                'file_name'   => $request->file('file')->getClientOriginalName(),
                'imported_at' => now(),
            ]);

            foreach ($rows as $idx => $r) {
                $month->items()->create([
                    'booking_date'     => $r['booking_date'],
                    'description'      => $r['description'] ?? null,
                    'counterparty'     => $r['counterparty'] ?? null,
                    'amount'           => $r['amount'] ?? 0,
                    'direction'        => $r['direction'] ?? 'out',
                    'reference'        => $r['reference'] ?? null,
                    'raw_row'          => $r['raw_row'] ?? null,
                    'is_important'     => true,
                    'settlement_group' => null,
                    'position'         => $idx + 1,
                ]);
            }

            return $month;
        });

        return redirect()->route('crafter.bank-statements.show', $month->id)
            ->with('message', 'Wyciąg zaimportowany: ' . count($rows) . ' pozycji.');
    }

    public function show(BankStatementMonth $bankStatementMonth): Response
    {
        $items = $bankStatementMonth->items()
            ->with('matched:id,name,amount,cost_planner_month_id')
            ->get();

        // Planer kosztów — niematchowane pozycje jako kandydaci do ręcznego wiązania.
        $costMonths = CostPlannerMonth::with(['items' => function ($q) {
            $q->select('id', 'cost_planner_month_id', 'name', 'amount', 'due_date', 'status', 'category');
        }])->orderByDesc('year')->orderByDesc('month')->get();

        // ID kosztów już zajętych przez jakikolwiek wyciąg (żeby nie pokazać w pickerze).
        $takenIds = BankStatementItem::whereNotNull('matched_id')
            ->where('matched_type', \App\Models\CostPlannerItem::class)
            ->pluck('matched_id')
            ->toArray();

        return Inertia::render('BankStatement/Show', [
            'month'      => $bankStatementMonth,
            'items'      => $items,
            'costMonths' => $costMonths,
            'takenCostIds' => $takenIds,
        ]);
    }

    public function destroy(BankStatementMonth $bankStatementMonth): RedirectResponse
    {
        if ($bankStatementMonth->file_path) {
            Storage::disk('local')->delete($bankStatementMonth->file_path);
        }
        $bankStatementMonth->delete();

        return redirect()->route('crafter.bank-statements.index')
            ->with('message', 'Wyciąg usunięty.');
    }
}
