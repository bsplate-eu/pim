<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Powiadomienie o nowej wiadomości (lub paczce nowych) w ARGO MAIL.
 * Wysyłane z MailSyncService — jedno na adresata na przebieg synchronizacji (anti-flood).
 */
class NewMailNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $count = 1,
        public ?string $fromName = null,
        public ?string $subject = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'webpush'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'    => 'mail',
            'count'   => $this->count,
            'from'    => $this->fromName,
            'subject' => $this->subject,
            'url'     => route('crafter.mobile.mail'),
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $title = $this->count > 1 ? "Nowe wiadomości ({$this->count})" : 'Nowy e-mail';

        $body = trim(($this->fromName ? $this->fromName . ': ' : '') . ($this->subject ?: '(bez tematu)'));
        if ($this->count > 1) {
            $body .= ' (+' . ($this->count - 1) . ' więcej)';
        }

        return (new WebPushMessage())
            ->title($title)
            ->body($body)
            ->icon('/icons/argo-192.png')
            ->badge('/icons/argo-192.png')
            ->tag('argo-mail')
            ->data(['url' => route('crafter.mobile.mail')]);
    }
}
