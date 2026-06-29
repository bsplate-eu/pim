<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Nadawca oznaczony jako SPAM — jego maile są ukrywane z głównej skrzynki
 * (folder „Spam") i auto-oznaczane przy synchronizacji.
 */
class SpamSender extends Model
{
    use HasFactory;

    protected $table = 'mail_spam_senders';

    protected $guarded = ['id'];
}
