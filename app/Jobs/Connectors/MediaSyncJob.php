<?php

namespace App\Jobs\Connectors;

use App\Models\Integration;
use App\Models\IntegrationConnectorRun;
use App\Services\Integration\Pipelines\MediaSyncPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class MediaSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries   = 5;
    public $backoff = [120, 120, 300, 300, 600];

    public function __construct(
        private int    $integrationId,
        private string $triggerType = 'chained',
        private ?int   $runId = null,
    ) {
        $this->onQueue('sync-media');
    }

    public function handle(): void
    {
        $integration = Integration::findOrFail($this->integrationId);

        // Run mógł powstać już przy kliknięciu (kontroler) — wtedy używamy go,
        // żeby status był widoczny od razu. Fallback (chain/cron): tworzymy nowy.
        $run = ($this->runId ? IntegrationConnectorRun::find($this->runId) : null)
            ?? IntegrationConnectorRun::create([
                'integration_id' => $integration->id,
                'connector'      => 'media',
                'status'         => 'pending',
                'trigger_type'   => $this->triggerType,
            ]);

        $pipeline = new MediaSyncPipeline();
        $pipeline->run($integration, $run);
    }

    public function failed(Throwable $exception): void
    {
        IntegrationConnectorRun::query()
            ->where('integration_id', $this->integrationId)
            ->where('connector', 'media')
            ->where('status', 'running')
            ->update([
                'status'      => 'failed',
                'message'     => $exception->getMessage(),
                'finished_at' => now(),
            ]);
    }
}
