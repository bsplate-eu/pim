<?php

namespace App\Listeners;

use App\Models\MailLog;
use Illuminate\Mail\Events\MessageSent;

class LogSentMail
{
    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $to = collect($message->getTo())->map(fn ($a) => $a->getAddress())->implode(', ');
            $templateKey = null;

            // Headers may carry X-Template-Key if dispatcher set it
            if ($message->getHeaders()->has('X-Template-Key')) {
                $templateKey = $message->getHeaders()->get('X-Template-Key')->getBodyAsString();
            }

            MailLog::create([
                'to_email' => mb_substr($to, 0, 255),
                'subject' => $message->getSubject(),
                'template_key' => $templateKey,
                'status' => MailLog::STATUS_SENT,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Never let logging break mail sending
            \Illuminate\Support\Facades\Log::warning('LogSentMail failed: ' . $e->getMessage());
        }
    }
}
