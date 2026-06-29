<?php

namespace App\Console\Commands;

use App\Jobs\SynchronizeIntegration;
use App\Jobs\SynchronizeSource;
use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\Source;
use App\Services\PrestashopService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SourcesSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sources:sync';

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
        Source::enabled()->get()->each(function ($source) {
            SynchronizeSource::dispatchSync($source->id);
        });
    }
}
