<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nagłówek Reply-To. Maile z formularzy kontaktowych mają w `From` NASZ adres
 * (żeby nie wpaść w spam), a prawdziwy adres klienta wyłącznie w `Reply-To`.
 * Bez tych kolumn odpowiedź wracała do nas, a adresu klienta nie było gdzie pokazać.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->string('reply_to_email', 255)->nullable()->after('from_name');
            $table->string('reply_to_name', 255)->nullable()->after('reply_to_email');
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropColumn(['reply_to_email', 'reply_to_name']);
        });
    }
};
