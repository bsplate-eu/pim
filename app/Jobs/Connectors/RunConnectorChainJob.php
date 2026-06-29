<?php

namespace App\Jobs\Connectors;

use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class RunConnectorChainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries   = 1;

    public function __construct(
        private int    $integrationId,
        private string $triggerType = 'manual',
    ) {
    }

    public function handle(): void
    {
        $integration = Integration::findOrFail($this->integrationId);

        // Add all enabled products to integration first
        $integration->addAllEnabledProducts();

        // Chain: Catalog Create → Catalog Delta (sequential on same queue)
        Bus::chain([
            new CatalogCreateJob($this->integrationId, $this->triggerType),
            new CatalogDeltaJob($this->integrationId, 'chained'),
        ])->onQueue('sync-catalog')->dispatch();

        // Media runs independently after Create populates the queue
        MediaSyncJob::dispatch($this->integrationId, 'chained')
            ->onQueue('sync-media')
            ->delay(now()->addSeconds(30)); // slight delay to let Create finish first batch

        // Blog is fully independent
        BlogSyncJob::dispatch($this->integrationId, $this->triggerType)
            ->onQueue('sync-blog');
    }
}
