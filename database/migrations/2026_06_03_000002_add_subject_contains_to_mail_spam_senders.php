<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Argo Mail — SPAM po nadawcy + fragmencie tytułu.
 * Pozwala oznaczyć jako spam tylko CZĘŚĆ maili od danego nadawcy (np. Allegro „Dyskusja"),
 * zostawiając resztę w skrzynce. Unikat: (from_email) → (from_email, subject_contains).
 * subject_contains = '' oznacza „cały nadawca" (jak dotychczas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_spam_senders', function (Blueprint $table) {
            $table->string('subject_contains')->default('')->after('from_email');
        });

        Schema::table('mail_spam_senders', function (Blueprint $table) {
            $table->dropUnique(['from_email']);
            $table->unique(['from_email', 'subject_contains']);
        });
    }

    public function down(): void
    {
        Schema::table('mail_spam_senders', function (Blueprint $table) {
            $table->dropUnique(['from_email', 'subject_contains']);
        });
        Schema::table('mail_spam_senders', function (Blueprint $table) {
            $table->dropColumn('subject_contains');
            $table->unique('from_email');
        });
    }
};
