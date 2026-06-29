<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Globalna konfiguracja powiadomień Signal o fakturach KSeF do zapłaty.
 * Jeden wiersz (singleton) — ustawienia wspólne dla wszystkich firm.
 * Wysyłka przez bramkę CallMeBot (HTTPS GET) — bez instalacji na serwerze.
 *
 * @see \App\Models\Ksef\KsefSignalSettings
 * @see \App\Console\Commands\KsefSignalDue
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_signal_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('phone')->nullable();                // numer odbiorcy (+48…) zarejestrowany w CallMeBot
            $table->string('api_key')->nullable();              // apikey z CallMeBot (Signal)
            $table->text('template')->nullable();               // szablon wiadomości ({pareto} {bsp} {data})
            $table->string('send_time', 5)->default('07:00');   // godzina dziennej wysyłki HH:MM
            $table->date('last_sent_date')->nullable();         // anty-duplikat: ostatni dzień wysyłki
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_signal_settings');
    }
};
