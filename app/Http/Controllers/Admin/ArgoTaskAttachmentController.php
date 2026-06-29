<?php

namespace App\Http\Controllers\Admin;

use App\Models\ArgoTask;
use App\Models\ArgoTaskActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArgoTaskAttachmentController extends Controller
{
    private const DIR = 'workspace-uploads';

    public function store(Request $request, ArgoTask $argoTask): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:102400', // 100 MB
            ],
        ]);

        $uploaded = $request->file('file');
        // Blokada rozszerzeń wykonywalnych (bezpieczeństwo — SSRF/RCE)
        $blocked  = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps', 'pl', 'py', 'cgi', 'sh', 'bash', 'exe', 'bat', 'cmd', 'dll', 'so', 'jar', 'asp', 'aspx', 'jsp', 'htaccess'];
        $name     = strtolower((string) $uploaded->getClientOriginalName());
        foreach ($blocked as $ext) {
            if (str_ends_with($name, '.' . $ext) || str_contains($name, '.' . $ext . '.')) {
                return response()->json(['error' => 'Disallowed file extension'], 422);
            }
        }

        $dir = public_path(self::DIR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $ext = strtolower($uploaded->getClientOriginalExtension() ?: 'bin');
        $filename = Str::uuid()->toString() . '.' . $ext;
        $uploaded->move($dir, $filename);

        $url = '/' . self::DIR . '/' . $filename;

        $payload = [
            'name' => $uploaded->getClientOriginalName(),
            'path' => self::DIR . '/' . $filename,
            'mime' => $uploaded->getClientMimeType(),
            'size' => filesize($dir . DIRECTORY_SEPARATOR . $filename) ?: 0,
            'url'  => $url,
        ];

        $activity = ArgoTaskActivity::create([
            'argo_task_id'  => $argoTask->id,
            'admin_user_id' => $request->user()?->id,
            'action'        => 'attachment_added',
            'payload'       => $payload,
        ]);

        return response()->json([
            'attachment' => array_merge($payload, ['activity_id' => $activity->id]),
        ]);
    }

    public function destroy(Request $request, ArgoTask $argoTask, int $activity): JsonResponse
    {
        $activityModel = ArgoTaskActivity::where('argo_task_id', $argoTask->id)
            ->where('action', 'attachment_added')
            ->findOrFail($activity);

        $path = $activityModel->payload['path'] ?? null;
        if ($path) {
            $full = public_path($path);
            if (is_file($full)) {
                @unlink($full);
            }
        }
        $activityModel->delete();

        return response()->json(['ok' => true]);
    }
}
