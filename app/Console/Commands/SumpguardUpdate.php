<?php

namespace App\Console\Commands;

use App\Services\SumpguardService;
use Illuminate\Console\Command;

class SumpguardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sumpguard:update';

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
        $service = new SumpguardService();
        $service->import();
    }
}
