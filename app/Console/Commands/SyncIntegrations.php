<?php

namespace App\Console\Commands;

use App\Jobs\SynchronizeIntegration;
use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Services\PrestashopService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncIntegrations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Integration::enabled()->get()->each(function ($integration) {
            SynchronizeIntegration::dispatch($integration->id);
        });
    }
}
