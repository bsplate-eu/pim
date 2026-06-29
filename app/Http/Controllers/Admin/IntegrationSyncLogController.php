<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\IntegrationConnectorRun;
use App\Models\IntegrationSyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\Integration\Pipelines\AbstractConnectorPipeline;

class IntegrationSyncLogController extends Controller
{
    public function index(): Response
    {
        $integrations = Integration::with(['syncLogs' => function ($q) {
            $q->latest()->limit(5);
        }])->get(['id', 'name', 'type']);

        return Inertia::render('Integration/Status', [
            'integrations' => $integrations,
        ]);
    }

    public function json(): JsonResponse
    {
        $legacyLogs = IntegrationSyncLog::with('integration:id,name,type')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($log) => [
                'id'               => "legacy-{$log->id}",
                'integration_id'   => $log->integration_id,
                'integration_name' => $log->integration->name ?? '—',
                'integration_type' => $log->integration->type ?? '',
                'connector'        => $log->current_item ?: 'legacy_sync',
                'status'           => $log->status,
                'progress'         => $log->progress,
                'total'            => $log->total,
                'progress_percent' => $log->progress_percent,
                'current_item'     => $log->current_item,
                'message'          => $log->message,
                'errors'           => $log->errors ?? [],
                'error_count'      => $log->error_count ?? 0,
                'duration'         => $log->duration,
                'started_at'       => $log->started_at?->format('Y-m-d H:i:s'),
                'finished_at'      => $log->finished_at?->format('Y-m-d H:i:s'),
                '_sort_at'         => $log->started_at?->timestamp ?? $log->created_at?->timestamp ?? 0,
            ]);

        $connectorRuns = IntegrationConnectorRun::with('integration:id,name,type')
            ->latest()
            ->limit(100)
            ->get()
            ->map(function (IntegrationConnectorRun $run) {
                $errors = collect($run->errors ?? [])->map(function (array $err) {
                    return [
                        'sku' => $err['id'] ?? '—',
                        'error' => $err['error'] ?? 'Unknown error',
                        'at' => $err['at'] ?? '',
                    ];
                })->values()->all();

                return [
                    'id'               => "connector-{$run->id}",
                    'integration_id'   => $run->integration_id,
                    'integration_name' => $run->integration->name ?? '—',
                    'integration_type' => $run->integration->type ?? '',
                    'connector'        => $run->connector,
                    'status'           => $run->status,
                    'progress'         => $run->progress,
                    'total'            => $run->total,
                    'progress_percent' => $run->progress_percent,
                    'current_item'     => $run->current_item,
                    'message'          => $run->message,
                    'errors'           => $errors,
                    'error_count'      => count($errors),
                    'duration'         => $run->duration,
                    'started_at'       => $run->started_at?->format('Y-m-d H:i:s'),
                    'finished_at'      => $run->finished_at?->format('Y-m-d H:i:s'),
                    '_sort_at'         => $run->started_at?->timestamp ?? $run->created_at?->timestamp ?? 0,
                ];
            });

        $merged = $legacyLogs
            ->concat($connectorRuns)
            ->sortByDesc('_sort_at')
            ->take(100)
            ->map(function (array $row) {
                unset($row['_sort_at']);
                return $row;
            })
            ->values();

        return response()->json($merged);
    }

    public function stopAllActive(): JsonResponse
    {
        Cache::put(AbstractConnectorPipeline::GLOBAL_STOP_CACHE_KEY, true, now()->addMinutes(5));

        $activeConnectorIntegrationIds = IntegrationConnectorRun::query()
            ->whereIn('status', ['pending', 'running'])
            ->pluck('integration_id')
            ->unique()
            ->values();

        $activeSyncLogIntegrationIds = IntegrationSyncLog::query()
            ->whereIn('status', ['pending', 'running'])
            ->pluck('integration_id')
            ->unique()
            ->values();

        $affectedConnectorRuns = IntegrationConnectorRun::query()
            ->whereIn('status', ['pending', 'running'])
            ->update([
                'status'      => 'failed',
                'message'     => 'Synchronization interrupted by user.',
                'finished_at' => now(),
            ]);

        $affectedSyncLogs = IntegrationSyncLog::query()
            ->whereIn('status', ['pending', 'running'])
            ->update([
                'status'      => 'failed',
                'message'     => 'Synchronization interrupted by user.',
                'finished_at' => now(),
            ]);

        $activeIntegrationIds = $activeConnectorIntegrationIds
            ->merge($activeSyncLogIntegrationIds)
            ->unique()
            ->values();

        foreach ($activeIntegrationIds as $integrationId) {
            Cache::forget("integration:{$integrationId}:sync");
        }

        $deletedQueuedJobs = 0;
        if (Schema::hasTable('jobs')) {
            $deletedQueuedJobs = DB::table('jobs')
                ->whereIn('queue', ['sync-catalog', 'sync-media', 'sync-blog', 'sync-analytics'])
                ->delete();
        }

        return response()->json([
            'message' => 'Synchronization stop requested.',
            'stopped_sync_logs' => $affectedSyncLogs,
            'stopped_connector_runs' => $affectedConnectorRuns,
            'deleted_queued_jobs' => $deletedQueuedJobs,
        ]);
    }
}
