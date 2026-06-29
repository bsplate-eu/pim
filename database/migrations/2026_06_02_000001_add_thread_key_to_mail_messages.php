<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            // Klucz wątku = hash(znormalizowany temat | adres drugiej strony). Maile od tej samej
            // osoby w tym samym temacie (też nasze odpowiedzi „Re: …") dostają ten sam klucz.
            $table->char('thread_key', 40)->nullable()->after('in_reply_to');
            $table->index(['account_id', 'thread_key']);
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'thread_key']);
            $table->dropColumn('thread_key');
        });
    }
};
