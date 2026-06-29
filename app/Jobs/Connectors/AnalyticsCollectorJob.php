<?php

namespace App\Jobs\Connectors;

use App\Models\Integration;
use App\Models\IntegrationConnectorRun;
use App\Services\Integration\Pipelines\AnalyticsCollectorPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AnalyticsCollectorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries   = 2;
    public $backoff = [300, 600];

    public function __construct(
        private int    $integrationId,
        private string $triggerType = 'scheduled',
    ) {
        $this->onQueue('sync-analytics');
    }

    public function handle(): void
    {
        $integration = Integration::findOrFail($this->integrationId);

        $run = IntegrationConnectorRun::create([
            'integration_id' => $integration->id,
            'connector'      => 'analytics',
            'status'         => 'pending',
            'trigger_type'   => $this->triggerType,
        ]);

        $pipeline = new AnalyticsCollectorPipeline();
        $pipeline->run($integration, $run);
    }

    public function failed(Throwable $exception): void
    {
        IntegrationConnectorRun::query()
            ->where('integration_id', $this->integrationId)
            ->where('connector', 'analytics')
            ->where('status', 'running')
            ->update([
                'status'      => 'failed',
                'message'     => $exception->getMessage(),
                'finished_at' => now(),
            ]);
    }
}
