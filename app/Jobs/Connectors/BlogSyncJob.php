<?php

namespace App\Jobs\Connectors;

use App\Models\Integration;
use App\Models\IntegrationConnectorRun;
use App\Services\Integration\Pipelines\BlogSyncPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class BlogSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries   = 3;
    public $backoff = [60, 120, 300];

    public function __construct(
        private int    $integrationId,
        private string $triggerType = 'manual',
        private ?int   $runId = null,
    ) {
        $this->onQueue('sync-blog');
    }

    public function handle(): void
    {
        $integration = Integration::findOrFail($this->integrationId);

        // Run mógł powstać już przy kliknięciu (kontroler) — wtedy używamy go,
        // żeby status był widoczny od razu. Fallback (chain/cron): tworzymy nowy.
        $run = ($this->runId ? IntegrationConnectorRun::find($this->runId) : null)
            ?? IntegrationConnectorRun::create([
                'integration_id' => $integration->id,
                'connector'      => 'blog',
                'status'         => 'pending',
                'trigger_type'   => $this->triggerType,
            ]);

        $pipeline = new BlogSyncPipeline();
        $pipeline->run($integration, $run);
    }

    public function failed(Throwable $exception): void
    {
        IntegrationConnectorRun::query()
            ->where('integration_id', $this->integrationId)
            ->where('connector', 'blog')
            ->where('status', 'running')
            ->update([
                'status'      => 'failed',
                'message'     => $exception->getMessage(),
                'finished_at' => now(),
            ]);
    }
}
