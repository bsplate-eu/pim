<?php

namespace App\Jobs;

use App\Exports\Admin\SellyIntegrationProductsExport;
use App\Jobs\Connectors\RunConnectorChainJob;
use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\IntegrationSyncLog;
use App\Services\Integration\IntegrationServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class SynchronizeIntegration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200;
    public $tries   = 1;

    public function __construct(private int $integration_id = 13)
    {
    }

    public function handle(): void
    {
        $integration = Integration::findOrFail($this->integration_id);

        // New connector architecture for PrestaShop, LiteCart and OpenCart
        if (in_array($integration->type, ['prestashop', 'litecart', 'opencart'])) {
            RunConnectorChainJob::dispatch($integration->id, 'manual');
            return;
        }

        // Legacy flows for Baselinker and Selly remain unchanged
        $log = IntegrationSyncLog::create([
            'integration_id' => $integration->id,
            'status'         => 'pending',
            'progress'       => 0,
            'total'          => 0,
        ]);

        try {
            $log->markRunning();

            $total = IntegrationProduct::where('integration_id', $integration->id)->count();
            $log->tick(0, $total ?: 1);

            $integration->addAllEnabledProducts();

            if ($integration->type === 'baselinker') {
                IntegrationProduct::where('integration_id', $integration->id)->update(['synced_at' => now()]);
                Cache::forget("baselinker_products_{$integration->id}");
                $log->tick($total, $total);

            } elseif ($integration->type === 'selly') {
                // Pełny feed CSV powstaje TU (queue, timeout 7200s) — generacja ~38s/1.5k prod
                // nie może iść w żądaniu HTTP. SellyController serwuje tylko gotowy plik z cache.
                $abs = storage_path('app/integrations/' . $integration->id . '.csv');
                if (!is_dir(dirname($abs))) {
                    mkdir(dirname($abs), 0775, true);
                }
                file_put_contents($abs, Excel::raw(new SellyIntegrationProductsExport($integration), ExcelWriter::CSV));
                $log->tick($total, $total);
            }

            $log->markCompleted("Zsynchronizowano {$log->fresh()->progress} produktów.");

        } catch (Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }
}
