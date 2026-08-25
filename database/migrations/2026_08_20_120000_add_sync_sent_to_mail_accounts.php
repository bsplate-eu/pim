<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Czy zaciągać z serwera folder „Wysłane" tej skrzynki.
 *
 * Dotąd sync pomijał wszystkie foldery specjalne (Wysłane/Kosz/Spam/Szkice), więc w PIM
 * widać było tylko pocztę wychodzącą wysłaną Z PIM — nie to, co poszło z Gmaila czy telefonu.
 * Domyślnie włączone: zakładka „Wysłane" ma pokazywać całą korespondencję wychodzącą.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->boolean('sync_sent')->default(true)->after('sync_window_months');
        });
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('sync_sent');
        });
    }
};
