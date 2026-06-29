<?php

namespace App\Console\Commands;

use App\Models\Mail\Message;
use Illuminate\Console\Command;

/**
 * Wylicza thread_key (temat + adres drugiej strony) dla istniejących maili.
 * Rerunnable — po wdrożeniu odpalić raz na prod, lokalnie po migracji.
 */
class MailBackfillThreadKeys extends Command
{
    protected $signature = 'mail:backfill-thread-keys {--chunk=500}';

    protected $description = 'Przelicza thread_key (wątkowanie) dla istniejących maili.';

    public function handle(): int
    {
        $total = 0;
        $changed = 0;

        Message::query()
            ->select(['id', 'subject', 'from_email', 'to_recipients', 'is_sent', 'thread_key'])
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($rows) use (&$total, &$changed) {
                foreach ($rows as $m) {
                    $key = Message::threadKeyFor($m->subject, $m->counterpartEmail());
                    if ($m->thread_key !== $key) {
                        // query-builder update — bez ruszania updated_at
                        Message::query()->whereKey($m->id)->update(['thread_key' => $key]);
                        $changed++;
                    }
                    $total++;
                }
                $this->info("…przetworzono {$total} (zmienione: {$changed})");
            });

        $this->info("Gotowe. Maili: {$total}, zaktualizowanych thread_key: {$changed}.");

        return self::SUCCESS;
    }
}
