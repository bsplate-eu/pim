<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * „Gotowe" wypada ze slownika etapow.
 *
 * Nie jest etapem produkcji: nie wynika ze sprzedazy, tylko z tego, ze kod ma
 * juz Projekt albo Team Steel. Liczy sie wiec na froncie z tych dwoch
 * znacznikow i zostaje wylacznie jako zakladka — bez koloru do ustawienia,
 * bez przedzialu, bez wiersza w Ustawieniach.
 *
 * Kody wskazujace na ten etap traca `stage_id` (FK ma nullOnDelete) i dostana
 * wlasciwy etap przy najblizszym „Przelicz etapy". Na produkcji w momencie
 * migracji nie wskazywal na niego zaden kod.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('production_stages')->where('name', 'Gotowe')->delete();
    }

    public function down(): void
    {
        $exists = DB::table('production_stages')->where('name', 'Gotowe')->exists();

        if ($exists) {
            return;
        }

        DB::table('production_stages')->insert([
            'name' => 'Gotowe',
            'color' => '#16a34a',
            'position' => (int) (DB::table('production_stages')->max('position') ?? 0) + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
