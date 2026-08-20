<?php

namespace App\Services\Mail;

use App\Models\Mail\Attachment;
use App\Models\Mail\Message;
use Webklex\IMAP\Facades\Client;

/**
 * Pobiera surową treść (bajty) załącznika e-maila z serwera IMAP na żądanie.
 *
 * PIM trzyma tylko metadane załączników (nazwa/mime/rozmiar) — sama treść leży
 * na skrzynce i jest dociągana leniwie. Logika lustrzana do
 * MailController::downloadAttachment (celowo NIE ruszamy tamtej, działającej ścieżki
 * pobierania), wydzielona tu do reużycia przez odczyt faktury (OCR).
 */
class MailAttachmentFetcher
{
    /**
     * @throws \RuntimeException gdy nie uda się pobrać (brak konta / wiadomości / załącznika / błąd IMAP)
     */
    public function fetchBytes(Message $message, Attachment $attachment): string
    {
        if ((int) $attachment->message_id !== (int) $message->id) {
            throw new \RuntimeException('Załącznik nie należy do tej wiadomości.');
        }

        $account = $message->account;
        if ($account === null) {
            throw new \RuntimeException('Brak konta pocztowego dla wiadomości.');
        }

        @ini_set('memory_limit', '512M'); // webklex parsuje cały MIME w pamięci — 128M FPM bywa za mało
        @set_time_limit(120);

        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '60');

        try {
            $client = Client::make($account->imapConfig());
            $client->connect();

            $path = $message->folder?->path ?? 'INBOX';
            $imapFolder = $client->getFolderByPath($path);
            if ($imapFolder === null) {
                throw new \RuntimeException('Nie znaleziono folderu IMAP.');
            }

            $imapMessage = $imapFolder->messages()
                ->whereUid($message->uid)
                ->setFetchBody(true)
                ->get()
                ->first();
            if ($imapMessage === null) {
                throw new \RuntimeException('Nie znaleziono wiadomości w skrzynce.');
            }

            $attachments = $imapMessage->getAttachments();
            $att = $attachments[$attachment->part_index] ?? $attachments->first();
            if ($att === null) {
                throw new \RuntimeException('Nie znaleziono załącznika w wiadomości.');
            }

            $content = (string) $att->content;
            $client->disconnect();

            return $content;
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }
    }
}
