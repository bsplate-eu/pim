<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapy produkcyjne przestaja byc zaszyte w kodzie (kolumny etap_1..gotowe)
 * i staja sie slownikiem zarzadzanym z Ustawien.
 *
 * `sales_from` / `sales_to` to przedzial sprzedazy 12M, po ktorym etap jest
 * przypisywany automatycznie. `sales_to` = NULL znaczy „bez gornej granicy".
 * Oba NULL = etap bez automatu (istnieje, ale nic sam nie lapie).
 *
 * Zasiew odtwarza cztery dotychczasowe etapy z ich kolorami, zeby po wdrozeniu
 * nic z ekranu nie znikelo. Przedzialy zostaja puste — progi ustawia uzytkownik,
 * bo rozklad sprzedazy (68% kodow ponizej 5 szt.) trzeba zobaczyc przed wyborem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 7);
            $table->unsignedInteger('sales_from')->nullable();
            $table->unsignedInteger('sales_to')->nullable();
            $table->unsignedInteger('position')->default(0)->index();
            $table->timestamps();
        });

        $now = Carbon::now();

        DB::table('production_stages')->insert([
            ['name' => 'Etap 1', 'color' => '#dc2626', 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Etap 2', 'color' => '#ea580c', 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Etap 3', 'color' => '#2563eb', 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Gotowe', 'color' => '#16a34a', 'position' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('production_stages');
    }
};
