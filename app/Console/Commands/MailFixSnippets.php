<?php

namespace App\Console\Commands;

use App\Models\Mail\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Przelicza snippety podglądu maili z zapisanej treści, używając Message::makeSnippet()
 * (usuwa CSS/JS z <style>/<script>). Naprawia maile HTML zsynchronizowane przed poprawką,
 * które w podglądzie pokazują surowy CSS (np. „a { text-decoration:none } .ReadMsgBody{…}").
 * Celuje tylko w maile bez plain-textu (tam był bug); rerunnable i bezpieczne.
 */
class MailFixSnippets extends Command
{
    protected $signature = 'mail:fix-snippets {--chunk=300}';

    protected $description = 'Argo Mail — przelicza snippety podglądu (usuwa CSS/JS z treści maili HTML)';

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');

        $chunk = max(50, (int) $this->option('chunk'));
        $seen = 0;
        $fixed = 0;

        Message::query()
            ->where(function ($q) {
                $q->whereNull('body_text')->orWhere('body_text', '');
            })
            ->whereNotNull('body_html')
            ->select(['id', 'body_text', 'body_html', 'snippet'])
            ->chunkById($chunk, function ($messages) use (&$seen, &$fixed) {
                foreach ($messages as $m) {
                    $seen++;
                    $new = Message::makeSnippet($m->body_text, $m->body_html);
                    if ($new !== $m->snippet) {
                        DB::table('mail_messages')->where('id', $m->id)->update(['snippet' => $new]);
                        $fixed++;
                    }
                }
                $this->line("   …przejrzano {$seen}, poprawiono {$fixed}");
            });

        $this->info("Gotowe. Przejrzano {$seen}, poprawiono {$fixed} snippetów.");

        return self::SUCCESS;
    }
}
