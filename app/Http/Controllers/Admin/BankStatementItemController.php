<?php

namespace App\Http\Controllers\Admin;

use App\Models\BankStatementItem;
use App\Models\CostPlannerItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankStatementItemController extends Controller
{
    public function update(Request $request, BankStatementItem $bankStatementItem): JsonResponse
    {
        $validated = $request->validate([
            'is_important'     => 'sometimes|boolean',
            'settlement_group' => ['sometimes', 'nullable', Rule::in(BankStatementItem::GROUPS)],
        ]);

        $bankStatementItem->update($validated);

        return response()->json(['item' => $bankStatementItem->fresh()]);
    }

    public function match(Request $request, BankStatementItem $bankStatementItem): JsonResponse
    {
        $validated = $request->validate([
            'cost_planner_item_id' => 'required|integer|exists:cost_planner_items,id',
        ]);

        // Zabezpieczenie: jeden koszt ↔ jeden wiersz wyciągu.
        $taken = BankStatementItem::where('matched_type', CostPlannerItem::class)
            ->where('matched_id', $validated['cost_planner_item_id'])
            ->where('id', '!=', $bankStatementItem->id)
            ->exists();

        if ($taken) {
            return response()->json(['error' => 'Ta pozycja kosztu jest już rozliczona z innym wyciągiem.'], 422);
        }

        $bankStatementItem->update([
            'matched_type' => CostPlannerItem::class,
            'matched_id'   => $validated['cost_planner_item_id'],
            'settlement_group' => $bankStatementItem->settlement_group ?? 'koszt',
        ]);

        return response()->json(['item' => $bankStatementItem->fresh('matched')]);
    }

    public function unmatch(BankStatementItem $bankStatementItem): JsonResponse
    {
        $bankStatementItem->update([
            'matched_type' => null,
            'matched_id'   => null,
        ]);

        return response()->json(['item' => $bankStatementItem->fresh()]);
    }
}
