<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ustawienia polaczenia z ARGO Bridge — integratorem, ktory czyta stan
 * magazynowy z Subiekta GT na maszynie w LAN-ie klienta.
 *
 * Kierunek ruchu jest jeden: Bridge WYPYCHA dane do PIM. PIM stoi publicznie,
 * Subiekt siedzi za NAT-em, wiec odwrotnie sie nie da — stad token, ktorym
 * Bridge sie przedstawia, a nie dane logowania do SQL po naszej stronie.
 *
 * Tabela jest singletonem (jeden wiersz zasiany od razu): jeden Bridge, jeden
 * magazyn. Gdyby kiedys doszedl drugi, ta tabela urosnie o wiersz, a nie o
 * kolumny.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_bridge', function (Blueprint $table) {
            $table->id();
            // Wylaczony = paczki sa odrzucane, nawet z poprawnym tokenem.
            $table->boolean('enabled')->default(false);
            // Symbol magazynu w Subiekcie GT, ktory czytamy jako „M3R".
            $table->string('warehouse_symbol')->nullable();
            // Token, ktorym Bridge sie przedstawia. NULL = polaczenie jeszcze
            // nieskonfigurowane; wtedy nie wpuszczamy nikogo.
            $table->string('api_token', 64)->nullable();
            // Ostatni kontakt JAKIKOLWIEK (ping) — z tego liczy sie „aktywny".
            $table->timestamp('last_seen_at')->nullable();
            // Ostatnia paczka ZE STANAMI i jej rozmiar — to co innego niz ping.
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedInteger('last_codes')->nullable();
            $table->string('bridge_version')->nullable();
            $table->timestamps();
        });

        $now = Carbon::now();

        // Zasiew pustego wiersza — ekran Ustawien ma miec co edytowac od razu,
        // bez tworzenia rekordu przy pierwszym wejsciu.
        DB::table('warehouse_bridge')->insert([
            'enabled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_bridge');
    }
};
