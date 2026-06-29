<?php

namespace App\Jobs;

use App\Exports\Admin\SellyIntegrationProductsExport;
use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\Source;
use App\Services\PrestashopService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;

class SynchronizeSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    /**
     * Create a new job instance.
     */
    public function __construct(private int $source_id = 11)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $source = Source::findOrFail($this->source_id);
        $source->synchronize();

    }
}
