<?php

namespace App\Console\Commands;

use App\Services\StahlService;
use Illuminate\Console\Command;

class StahlUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stahl:update';

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
        $service = new StahlService();
        $service->import();
    }
}
