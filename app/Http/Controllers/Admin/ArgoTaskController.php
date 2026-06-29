<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\ArgoProject;
use App\Models\ArgoTask;
use App\Models\ArgoTaskActivity;
use App\Notifications\UserMentionedInTaskNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ArgoTaskController extends Controller
{
    public function show(ArgoTask $argoTask): Response
    {
        $argoTask->load([
            'project',
            'assignees:id,first_name,last_name,email',
            'activities' => fn ($q) => $q->with('user:id,first_name,last_name,email')->limit(100),
        ]);

        $attachments = $argoTask->activities()
            ->where('action', 'attachment_added')
            ->get()
            ->map(fn ($a) => array_merge((array) ($a->payload ?? []), ['activity_id' => $a->id]));

        $project = $argoTask->project;

        return Inertia::render('ArgoTask/TaskShow', [
            'task'            => $argoTask,
            'project'         => $project,
            'columns'         => $project->columnsMap(),
            'columnColors'    => $project->columnColorsMap(),
            'priorities'      => $project->priorityNames(),
            'priorityColors'  => $project->priorityColorsMap(),
            'labelOptions'    => $project->labelNames(),
            'labelColors'     => $project->labelColorsMap(),
            'users'           => AdminUser::select('id', 'first_name', 'last_name', 'email')->get(),
            'assignees'       => $argoTask->assignees,
            'activities'      => $argoTask->activities,
            'attachments'     => $attachments,
        ]);
    }

    public function store(Request $request, ArgoProject $argoProject): RedirectResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'description'   => 'nullable|string',
            'kanban_column' => ['required', Rule::in($argoProject->columnKeys())],
            'priority'      => ['nullable', Rule::in($argoProject->priorityNames())],
            'labels'        => 'nullable|array',
            'labels.*'      => 'string|max:50',
            'due_date'      => 'nullable|date',
        ]);

        $task = $argoProject->tasks()->create($validated);

        ArgoTaskActivity::create([
            'argo_task_id'  => $task->id,
            'admin_user_id' => $request->user()?->id,
            'action'        => 'created',
            'payload'       => ['name' => $task->name],
        ]);

        return back()->with('message', 'Zadanie dodane.');
    }

    public function update(Request $request, ArgoTask $argoTask): RedirectResponse
    {
        $project = $argoTask->project;

        $validated = $request->validate([
            'name'          => 'sometimes|required|string|max:255',
            'description'   => 'nullable|string',
            'content'       => 'nullable|string',
            'kanban_column' => ['sometimes', 'required', Rule::in($project->columnKeys())],
            'priority'      => ['nullable', Rule::in($project->priorityNames())],
            'labels'        => 'nullable|array',
            'labels.*'      => 'string|max:50',
            'due_date'      => 'nullable|date',
            'position'      => 'nullable|integer|min:0',
        ]);

        $statusChanged = isset($validated['kanban_column']) && $validated['kanban_column'] !== $argoTask->kanban_column;
        $argoTask->update($validated);

        if ($statusChanged) {
            ArgoTaskActivity::create([
                'argo_task_id'  => $argoTask->id,
                'admin_user_id' => $request->user()?->id,
                'action'        => 'status_changed',
                'payload'       => ['to' => $validated['kanban_column']],
            ]);
        }

        return back()->with('message', 'Zadanie zaktualizowane.');
    }

    public function destroy(ArgoTask $argoTask): RedirectResponse
    {
        $argoTask->delete();
        return back()->with('message', 'Zadanie usunięte.');
    }

    public function move(Request $request, ArgoTask $argoTask): JsonResponse
    {
        $project = $argoTask->project;

        $request->validate([
            'kanban_column' => ['required', Rule::in($project->columnKeys())],
            'position'      => 'nullable|integer|min:0',
        ]);

        $previousColumn = $argoTask->kanban_column;
        $argoTask->update([
            'kanban_column' => $request->kanban_column,
            'position'      => $request->position ?? 0,
        ]);

        if ($previousColumn !== $request->kanban_column) {
            ArgoTaskActivity::create([
                'argo_task_id'  => $argoTask->id,
                'admin_user_id' => $request->user()?->id,
                'action'        => 'status_changed',
                'payload'       => ['from' => $previousColumn, 'to' => $request->kanban_column],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function updateContent(Request $request, ArgoTask $argoTask): JsonResponse
    {
        $data = $request->validate([
            'content' => 'nullable|string|max:2097152', // 2 MB
        ]);

        $content = $data['content'] ?? '';

        // Parse mentions
        $newMentionIds = [];
        if ($content !== '') {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//span[@data-mention]') ?: [] as $node) {
                $id = (int) $node->getAttribute('data-user-id');
                if ($id > 0) {
                    $newMentionIds[] = $id;
                }
            }
            $newMentionIds = array_values(array_unique($newMentionIds));
        }

        $previousIds = (array) ($argoTask->activities()
            ->where('action', 'mentioned')
            ->latest('created_at')
            ->first()
            ?->payload['user_ids'] ?? []);

        $freshlyMentioned = array_values(array_diff($newMentionIds, $previousIds));

        $argoTask->update(['content' => $content]);

        $actor = $request->user();
        if ($freshlyMentioned) {
            foreach (AdminUser::whereIn('id', $freshlyMentioned)->get() as $user) {
                if (!$actor || $actor->id !== $user->id) {
                    $user->notify(new UserMentionedInTaskNotification($argoTask, $actor, $this->excerpt($content)));
                }
            }
            ArgoTaskActivity::create([
                'argo_task_id'  => $argoTask->id,
                'admin_user_id' => $actor?->id,
                'action'        => 'mentioned',
                'payload'       => ['user_ids' => $newMentionIds, 'fresh' => $freshlyMentioned],
            ]);
        }

        // Log 'updated' — dedupe (last updated > 60s temu)
        $lastUpdate = $argoTask->activities()
            ->where('action', 'updated')
            ->latest('created_at')
            ->first();

        if (!$lastUpdate || $lastUpdate->created_at->lt(now()->subMinute())) {
            ArgoTaskActivity::create([
                'argo_task_id'  => $argoTask->id,
                'admin_user_id' => $actor?->id,
                'action'        => 'updated',
                'payload'       => ['field' => 'content'],
            ]);
        }

        return response()->json([
            'ok'         => true,
            'saved_at'   => now()->toIso8601String(),
        ]);
    }

    private function excerpt(string $html, int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . '…';
    }
}
