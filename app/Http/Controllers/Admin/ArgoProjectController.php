<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\ArgoProject;
use App\Models\ArgoProjectGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ArgoProjectController extends Controller
{
    public function create(ArgoProjectGroup $argoProjectGroup): Response
    {
        return Inertia::render('ArgoTask/CreateProject', [
            'group' => $argoProjectGroup->only(['id', 'name']),
        ]);
    }

    public function store(Request $request, ArgoProjectGroup $argoProjectGroup): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:32',
            'color'       => 'nullable|string|max:32',
        ]);

        $validated['argo_project_group_id'] = $argoProjectGroup->id;
        $validated['columns']    = ArgoProject::DEFAULT_COLUMNS;
        $validated['labels']     = ArgoProject::DEFAULT_LABELS;
        $validated['priorities'] = ArgoProject::DEFAULT_PRIORITIES;

        $project = ArgoProject::create($validated);

        return redirect()->route('crafter.argo-task.projects.show', $project->id)
            ->with('message', 'Projekt utworzony.');
    }

    public function show(ArgoProject $argoProject): Response
    {
        $argoProject->load('group:id,name');

        $tasks = $argoProject->tasks()
            ->with('assignees:id,first_name,last_name,email')
            ->orderBy('kanban_column')
            ->orderBy('position')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('kanban_column');

        $users = AdminUser::select('id', 'first_name', 'last_name', 'email')->get();

        return Inertia::render('ArgoTask/Show', [
            'project'         => $argoProject,
            'tasksByColumn'   => $tasks,
            'columns'         => $argoProject->columnsMap(),
            'columnColors'    => $argoProject->columnColorsMap(),
            'priorities'      => $argoProject->priorityNames(),
            'priorityColors'  => $argoProject->priorityColorsMap(),
            'labelOptions'    => $argoProject->labelNames(),
            'labelColors'     => $argoProject->labelColorsMap(),
            'boardConfig'     => [
                'columns'    => $argoProject->columnsList(),
                'labels'     => $argoProject->labelsList(),
                'priorities' => $argoProject->prioritiesList(),
            ],
            'allowedColors'   => ArgoProject::ALLOWED_COLORS,
            'users'           => $users,
        ]);
    }

    public function update(Request $request, ArgoProject $argoProject): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:32',
            'color'       => 'nullable|string|max:32',
        ]);

        $argoProject->update($validated);

        return back()->with('message', 'Projekt zaktualizowany.');
    }

    public function updateConfig(Request $request, ArgoProject $argoProject): RedirectResponse
    {
        $colorRule = Rule::in(ArgoProject::ALLOWED_COLORS);

        $validated = $request->validate([
            'columns'                => 'required|array|min:1|max:20',
            'columns.*.key'          => 'nullable|string|max:64|regex:/^[a-z0-9_]+$/',
            'columns.*.name'         => 'required|string|max:64',
            'columns.*.color'        => ['required', 'string', $colorRule],

            'labels'                 => 'present|array|max:50',
            'labels.*.name'          => 'required|string|max:50',
            'labels.*.color'         => ['required', 'string', $colorRule],

            'priorities'             => 'present|array|max:20',
            'priorities.*.name'      => 'required|string|max:32',
            'priorities.*.color'     => ['required', 'string', $colorRule],
        ]);

        // Normalizacja kolumn: zapewnij unikalne klucze (zachowaj istniejące, wygeneruj brakujące).
        $existingKeys = $argoProject->columnKeys();
        $usedKeys = [];
        $normalizedColumns = [];

        foreach ($validated['columns'] as $col) {
            $key = $col['key'] ?? null;
            if (!$key || !in_array($key, $existingKeys, true) || in_array($key, $usedKeys, true)) {
                $key = $this->generateColumnKey($col['name'], $usedKeys);
            }
            $usedKeys[] = $key;
            $normalizedColumns[] = [
                'key'   => $key,
                'name'  => $col['name'],
                'color' => $col['color'],
            ];
        }

        // Re-mapowanie taśków dla usuniętych kolumn → pierwsza dostępna kolumna.
        $remainingKeys = array_column($normalizedColumns, 'key');
        $orphanTasks = $argoProject->tasks()->whereNotIn('kanban_column', $remainingKeys)->get();
        if ($orphanTasks->isNotEmpty()) {
            $fallback = $remainingKeys[0];
            foreach ($orphanTasks as $t) {
                $t->update(['kanban_column' => $fallback]);
            }
        }

        // Re-mapowanie usuniętych priorytetów → null.
        $remainingPriorities = array_column($validated['priorities'], 'name');
        $argoProject->tasks()
            ->whereNotNull('priority')
            ->whereNotIn('priority', $remainingPriorities)
            ->update(['priority' => null]);

        // Etykiet nie migrujemy — są tagami w json, pozostaną dopóki ktoś nie zapisze taska.

        $argoProject->update([
            'columns'    => $normalizedColumns,
            'labels'     => array_values($validated['labels']),
            'priorities' => array_values($validated['priorities']),
        ]);

        return back()->with('message', 'Ustawienia tablicy zapisane.');
    }

    public function destroy(ArgoProject $argoProject): RedirectResponse
    {
        $groupId = $argoProject->argo_project_group_id;
        $argoProject->delete();

        return redirect()->route('crafter.argo-task.groups.show', $groupId)
            ->with('message', 'Projekt usunięty.');
    }

    private function generateColumnKey(string $name, array $taken): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name));
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'col';
        }
        $key = $base;
        $i = 2;
        while (in_array($key, $taken, true)) {
            $key = $base . '_' . $i;
            $i++;
        }
        return $key;
    }
}
