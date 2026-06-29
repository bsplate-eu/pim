<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;

/**
 * Reguła wykluczenia z grupowania (wątkowania): maile od nadawcy/domeny (+ opcjonalny
 * fragment tytułu) dostają UNIKATOWY thread_key, więc nie są zwijane w jeden wątek.
 * Używane np. dla zamówień Allegro/Amazon (jeden adres, podobny temat = osobne sprawy).
 */
class ThreadExclude extends Model
{
    protected $table = 'mail_thread_excludes';

    protected $guarded = ['id'];
}
