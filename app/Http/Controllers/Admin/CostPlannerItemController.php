<?php

namespace App\Http\Controllers\Admin;

use App\Models\CostPlannerItem;
use App\Models\CostPlannerMonth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostPlannerItemController extends Controller
{
    private function rules(bool $partial = false): array
    {
        return [
            'name'           => 'sometimes|nullable|string|max:255',
            'amount'         => $partial ? 'sometimes|numeric|min:0' : 'nullable|numeric|min:0',
            'status'         => 'sometimes|nullable|string|max:64',
            'due_date'       => 'sometimes|nullable|date',
            'category'       => 'sometimes|nullable|string|max:64',
            'type'           => 'sometimes|nullable|string|max:64',
            'invoice_number' => 'sometimes|nullable|string|max:64',
            'currency'       => 'sometimes|nullable|string|max:3',
            'position'       => 'sometimes|integer',
        ];
    }

    public function store(Request $request, CostPlannerMonth $costPlannerMonth): JsonResponse
    {
        $validated = $request->validate($this->rules(false));

        $validated['position'] = ($costPlannerMonth->items()->max('position') ?? 0) + 1;
        $validated['status']   = $validated['status']   ?? 'Do zapłaty';
        $validated['currency'] = $validated['currency'] ?? 'PLN';
        $validated['amount']   = $validated['amount']   ?? 0;

        $item = $costPlannerMonth->items()->create($validated);

        return response()->json(['item' => $item], 201);
    }

    public function update(Request $request, CostPlannerItem $costPlannerItem): JsonResponse
    {
        $validated = $request->validate($this->rules(true));

        $costPlannerItem->update($validated);

        return response()->json(['item' => $costPlannerItem->fresh()]);
    }

    public function destroy(CostPlannerItem $costPlannerItem): JsonResponse
    {
        $costPlannerItem->delete();

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request, CostPlannerMonth $costPlannerMonth): JsonResponse
    {
        $validated = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer|exists:cost_planner_items,id',
        ]);

        foreach ($validated['ids'] as $idx => $id) {
            CostPlannerItem::where('id', $id)
                ->where('cost_planner_month_id', $costPlannerMonth->id)
                ->update(['position' => $idx + 1]);
        }

        return response()->json(['ok' => true]);
    }
}
