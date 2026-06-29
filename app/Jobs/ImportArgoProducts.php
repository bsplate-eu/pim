<?php

namespace App\Jobs;

use App\Imports\ArgolProductsImport;
use App\Imports\DogdesignProductsImport;
use App\Imports\VirsalProductsImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;


class ImportArgoProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $filePath = storage_path('app/argo/WeltPlast.csv');
        Excel::import(new ArgolProductsImport, $filePath);

    }
}
