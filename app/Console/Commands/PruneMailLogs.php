<?php

namespace App\Console\Commands;

use App\Models\MailLog;
use Illuminate\Console\Command;

class PruneMailLogs extends Command
{
    protected $signature = 'mail:prune-logs {--days=30 : Keep logs younger than this many days}';

    protected $description = 'Delete mail_logs older than N days (default: 30).';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $this->error('--days must be > 0');
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = MailLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} mail log(s) older than {$days} days.");
        return self::SUCCESS;
    }
}
