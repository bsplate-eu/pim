<?php

namespace App\Jobs\Mail;

use App\Models\Mail\Account;
use App\Services\Mail\MailSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMailAccountJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $accountId)
    {
    }

    public function handle(MailSyncService $service): void
    {
        $account = Account::find($this->accountId);

        if (! $account || ! $account->is_active) {
            return;
        }

        $service->sync($account);
    }
}
