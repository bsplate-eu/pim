<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class DatabaseBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup';

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
        $this->info('Start backup...');

        try {
            $service = new BackupService();
            $service->cleanBakcups();
            $service->backup();
            $this->info(__('Backup created successfully'));

        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }

    }
}
