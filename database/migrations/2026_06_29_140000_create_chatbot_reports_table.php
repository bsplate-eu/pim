<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Argo Connect → Integracja chatboot.
 * Konfiguracja raportów wysyłanych na WhatsApp (CallMeBot). Jeden wiersz na raport
 * (report_key, np. 'sales'). phone/api_key puste = współdzielone z konfiguracją KSeF.
 *
 * @see \App\Models\Connect\ChatbotReport
 * @see \App\Console\Commands\ConnectSalesReport
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_key')->unique();             // 'sales' (na przyszłość: kolejne raporty)
            $table->boolean('enabled')->default(false);
            $table->text('template')->nullable();               // szablon treści (placeholdery)
            $table->string('send_time', 5)->default('20:00');   // godzina dziennej wysyłki HH:MM
            $table->string('phone')->nullable();                // odbiorca; puste = jak w KSeF
            $table->string('api_key')->nullable();              // apikey CallMeBot; puste = jak w KSeF
            $table->date('last_sent_date')->nullable();         // anty-duplikat
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_reports');
    }
};
