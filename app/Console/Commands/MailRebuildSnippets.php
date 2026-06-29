<?php

namespace App\Console\Commands;

use App\Models\Mail\Message;
use Illuminate\Console\Command;

/**
 * Przelicza snippet (podgląd) istniejących maili — usuwa CSS/JS z sekcji <style>/<script>,
 * które wcześniej wyciekały do podglądu. Rerunnable; po wdrożeniu odpalić raz na prod.
 */
class MailRebuildSnippets extends Command
{
    protected $signature = 'mail:rebuild-snippets {--chunk=300}';

    protected $description = 'Przelicza snippet maili (usuwa wyciekły CSS/JS z podglądu).';

    public function handle(): int
    {
        $total = 0;
        $changed = 0;

        Message::query()
            ->select(['id', 'body_text', 'body_html', 'snippet'])
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($rows) use (&$total, &$changed) {
                foreach ($rows as $m) {
                    $snip = Message::makeSnippet($m->body_text, $m->body_html);
                    if ($snip !== $m->snippet) {
                        Message::query()->whereKey($m->id)->update(['snippet' => $snip]);
                        $changed++;
                    }
                    $total++;
                }
                $this->info("…przetworzono {$total} (poprawionych: {$changed})");
            });

        $this->info("Gotowe. Maili: {$total}, snippetów poprawionych: {$changed}.");

        return self::SUCCESS;
    }
}
