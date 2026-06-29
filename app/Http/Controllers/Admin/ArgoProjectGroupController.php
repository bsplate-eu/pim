<?php

namespace App\Http\Controllers\Admin;

use App\Models\ArgoProjectGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArgoProjectGroupController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('ArgoTask/CreateGroup');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:32',
            'color'       => 'nullable|string|max:32',
        ]);

        $group = ArgoProjectGroup::create($validated);

        return redirect()->route('crafter.argo-task.groups.show', $group->id)
            ->with('message', 'Grupa utworzona.');
    }

    public function show(ArgoProjectGroup $argoProjectGroup): Response
    {
        $argoProjectGroup->load(['projects' => function ($q) {
            $q->withCount('tasks');
        }]);

        return Inertia::render('ArgoTask/GroupShow', [
            'group'    => $argoProjectGroup,
            'projects' => $argoProjectGroup->projects,
        ]);
    }

    public function edit(ArgoProjectGroup $argoProjectGroup): Response
    {
        return Inertia::render('ArgoTask/EditGroup', [
            'group' => $argoProjectGroup,
        ]);
    }

    public function update(Request $request, ArgoProjectGroup $argoProjectGroup): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:32',
            'color'       => 'nullable|string|max:32',
            'position'    => 'nullable|integer|min:0',
        ]);

        $argoProjectGroup->update($validated);

        return back()->with('message', 'Grupa zaktualizowana.');
    }

    public function destroy(ArgoProjectGroup $argoProjectGroup): RedirectResponse
    {
        // Bezpieczniej: zablokuj jeśli grupa ma projekty. Uniknie przypadkowej kaskadowej katastrofy.
        if ($argoProjectGroup->projects()->exists()) {
            return back()->withErrors([
                'group' => 'Nie można usunąć grupy zawierającej projekty. Najpierw przenieś lub usuń projekty.',
            ]);
        }

        $argoProjectGroup->delete();

        return redirect()->route('crafter.home')
            ->with('message', 'Grupa usunięta.');
    }
}
