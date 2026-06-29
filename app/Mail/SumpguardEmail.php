<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SumpguardEmail extends Mailable
{
    use SerializesModels;

    public array $diffs;
    public array $news;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(array $diffs, array $news)
    {
        $this->diffs = $diffs;
        $this->news = $news;
    }

    /**
     * Get the message envelope.
     *
     * @return \Illuminate\Mail\Mailables\Envelope
     */
    public function envelope()
    {
        $date = now()->toDateString();
        return new Envelope(
            subject: "Sumpguard Zmiany JSON $date",
        );
    }

    /**
     * Get the message content definition.
     *
     * @return \Illuminate\Mail\Mailables\Content
     */
    public function content()
    {
        return new Content(
            html: 'email.sumpguard',
            with: [
                'diffs' => $this->diffs,
                'news' => $this->news,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array
     */
    public function attachments()
    {
        $current_path = 'sumpguard/current.json';
        $prev_path = 'sumpguard/prev.json';

        return [
//            Attachment::fromStorage($current_path),
//            Attachment::fromStorage($prev_path),
        ];
    }
}
