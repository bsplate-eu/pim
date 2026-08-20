<?php

namespace App\Console\Commands;

use App\Models\Mail\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dekoduje istniejące, zakodowane MIME nagłówki (RFC 2047) maili zsynchronizowanych ZANIM
 * MailSyncService zaczął dekodować sam — webklex bez rozszerzenia `imap` zapisywał surowe
 * „=?UTF-8?Q?...?=" jako temat / nazwę nadawcy. Przelicza:
 *   • subject, from_name, nazwy w to_recipients/cc_recipients,
 *   • thread_key (temat po dekodowaniu się zmienia → wątkowanie musi nadążyć),
 *   • filename załączników (mail_attachments).
 * Rerunnable i bezpieczne — rusza tylko wiersze, w których faktycznie coś się zmienia
 * (query-builder update, bez dotykania updated_at).
 */
class MailRedecodeHeaders extends Command
{
    protected $signature = 'mail:redecode-headers {--chunk=500}';

    protected $description = 'Argo Mail — dekoduje zakodowane MIME nagłówki (temat, nadawca, odbiorcy, załączniki) istniejących maili.';

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');

        $chunk = max(50, (int) $this->option('chunk'));
        $seen = 0;
        $fixedMsgs = 0;
        $fixedThreadKeys = 0;

        Message::query()
            ->select(['id', 'subject', 'from_name', 'from_email', 'to_recipients', 'cc_recipients', 'is_sent', 'thread_key'])
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$seen, &$fixedMsgs, &$fixedThreadKeys) {
                foreach ($rows as $m) {
                    $seen++;

                    $patch = [];

                    $subject = Message::decodeMimeHeader($m->subject);
                    if ($subject !== (string) $m->subject) {
                        $patch['subject'] = $subject !== '' ? $subject : null;
                    }

                    $fromName = Message::decodeMimeHeader($m->from_name);
                    if ($fromName !== (string) $m->from_name) {
                        $patch['from_name'] = $fromName !== '' ? $fromName : null;
                    }

                    foreach (['to_recipients', 'cc_recipients'] as $col) {
                        $decoded = $this->decodeRecipients($m->{$col});
                        if ($decoded !== null) {
                            $patch[$col] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                        }
                    }

                    // thread_key liczymy z aktualnego (już zdekodowanego) tematu.
                    $key = Message::threadKeyFor($subject, $m->counterpartEmail());
                    if ($key !== $m->thread_key) {
                        $patch['thread_key'] = $key;
                        $fixedThreadKeys++;
                    }

                    if ($patch) {
                        DB::table('mail_messages')->where('id', $m->id)->update($patch);
                        if (array_diff_key($patch, ['thread_key' => true])) {
                            $fixedMsgs++;
                        }
                    }
                }
                $this->line("   …przejrzano {$seen}, poprawiono nagłówków: {$fixedMsgs}, thread_key: {$fixedThreadKeys}");
            });

        // Nazwy załączników (osobna tabela) — dekodujemy tylko te z „=?" w nazwie.
        $fixedAtt = 0;
        DB::table('mail_attachments')
            ->select(['id', 'filename'])
            ->where('filename', 'like', '%=?%')
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$fixedAtt) {
                foreach ($rows as $a) {
                    $decoded = mb_substr(Message::decodeMimeHeader($a->filename), 0, 255);
                    if ($decoded !== '' && $decoded !== (string) $a->filename) {
                        DB::table('mail_attachments')->where('id', $a->id)->update(['filename' => $decoded]);
                        $fixedAtt++;
                    }
                }
            });

        $this->info("Gotowe. Przejrzano {$seen} maili — poprawiono nagłówków: {$fixedMsgs}, thread_key: {$fixedThreadKeys}, załączników: {$fixedAtt}.");

        return self::SUCCESS;
    }

    /**
     * Dekoduje nazwy (personal) w liście adresatów [{email,name}, …].
     * Zwraca nową tablicę gdy coś się zmieniło, albo null gdy bez zmian / brak danych.
     *
     * @param  array<int, array{email?: string, name?: string}>|null  $recipients
     * @return array<int, array{email?: string, name?: string}>|null
     */
    private function decodeRecipients(?array $recipients): ?array
    {
        if (empty($recipients)) {
            return null;
        }

        $changed = false;
        foreach ($recipients as $i => $r) {
            $name = (string) ($r['name'] ?? '');
            if ($name === '' || ! str_contains($name, '=?')) {
                continue;
            }
            $decoded = Message::decodeMimeHeader($name);
            if ($decoded !== $name) {
                $recipients[$i]['name'] = $decoded;
                $changed = true;
            }
        }

        return $changed ? $recipients : null;
    }
}
