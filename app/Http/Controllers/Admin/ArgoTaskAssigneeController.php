<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\ArgoTask;
use App\Models\ArgoTaskActivity;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArgoTaskAssigneeController extends Controller
{
    public function store(Request $request, ArgoTask $argoTask): JsonResponse
    {
        $data = $request->validate([
            'admin_user_id' => 'required|integer|exists:admin_users,id',
        ]);

        $userId = (int) $data['admin_user_id'];
        $actor  = $request->user();

        if (!$argoTask->assignees()->where('admin_users.id', $userId)->exists()) {
            $argoTask->assignees()->attach($userId, [
                'assigned_by' => $actor?->id,
                'assigned_at' => now(),
            ]);

            ArgoTaskActivity::create([
                'argo_task_id'  => $argoTask->id,
                'admin_user_id' => $actor?->id,
                'action'        => 'assigned',
                'payload'       => ['user_id' => $userId],
            ]);

            if ($user = AdminUser::find($userId)) {
                if (!$actor || $actor->id !== $user->id) {
                    $user->notify(new TaskAssignedNotification($argoTask, $actor));
                }
            }
        }

        return response()->json([
            'assignees' => $argoTask->assignees()->get(['admin_users.id', 'first_name', 'last_name', 'email']),
        ]);
    }

    public function destroy(Request $request, ArgoTask $argoTask, int $user): JsonResponse
    {
        $argoTask->assignees()->detach($user);

        ArgoTaskActivity::create([
            'argo_task_id'  => $argoTask->id,
            'admin_user_id' => $request->user()?->id,
            'action'        => 'unassigned',
            'payload'       => ['user_id' => $user],
        ]);

        return response()->json([
            'assignees' => $argoTask->assignees()->get(['admin_users.id', 'first_name', 'last_name', 'email']),
        ]);
    }
}
