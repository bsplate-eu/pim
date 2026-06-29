<?php

namespace App\Http\Controllers\Admin;

use App\Models\CostPlannerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CostPlannerSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = CostPlannerSettings::instance();

        return Inertia::render('CostPlanner/Settings', [
            'settings'       => $settings->toPayload(),
            'allowedColors'  => CostPlannerSettings::ALLOWED_COLORS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $colorRule = Rule::in(CostPlannerSettings::ALLOWED_COLORS);

        $validated = $request->validate([
            'cost_names'        => 'present|array|max:200',
            'cost_names.*'      => 'required|string|max:255',

            'statuses'          => 'present|array|max:30',
            'statuses.*.name'   => 'required|string|max:64',
            'statuses.*.color'  => ['required', 'string', $colorRule],

            'categories'        => 'present|array|max:50',
            'categories.*.name' => 'required|string|max:64',
            'categories.*.color'=> ['required', 'string', $colorRule],

            'types'             => 'present|array|max:30',
            'types.*.name'      => 'required|string|max:64',
            'types.*.color'     => ['required', 'string', $colorRule],

            'currencies'        => 'present|array|max:20',
            'currencies.*'      => 'required|string|size:3',
        ]);

        $settings = CostPlannerSettings::instance();
        $settings->update([
            'cost_names' => array_values(array_unique($validated['cost_names'])),
            'statuses'   => array_values($validated['statuses']),
            'categories' => array_values($validated['categories']),
            'types'      => array_values($validated['types']),
            'currencies' => array_values(array_unique(array_map('strtoupper', $validated['currencies']))),
        ]);

        return back()->with('message', 'Ustawienia zapisane.');
    }
}
