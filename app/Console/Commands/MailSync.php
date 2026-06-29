<?php

namespace App\Console\Commands;

use App\Models\Mail\Account;
use App\Services\Mail\MailSyncService;
use Illuminate\Console\Command;

class MailSync extends Command
{
    protected $signature = 'mail:sync {--account= : ID konkretnej skrzynki}';

    protected $description = 'Argo Mail — synchronizacja skrzynek (wszystkie zwykłe foldery, przyrostowo, inline)';

    public function handle(MailSyncService $sync): int
    {
        $query = Account::query()->where('is_active', true);

        if ($id = $this->option('account')) {
            $query->where('id', $id);
        }

        $accounts = $query->get();

        foreach ($accounts as $account) {
            $r = $sync->sync($account);
            $this->info("{$account->label}: ".($r['ok']
                ? "pobrano {$r['fetched']}, nowych {$r['new']}"
                : 'BŁĄD '.($r['message'] ?? '')));
        }

        $this->info("Przetworzone skrzynki: {$accounts->count()}");

        return self::SUCCESS;
    }
}
