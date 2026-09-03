<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sufiksy MATERIALOWE — jedyne, ktore trzon ucina.
 *
 * Pierwsza wersja grupowania ucinala wszystkie koncowe litery i przez to
 * wciagala 30.144W pod 30.144, a 30.145A pod 30.145. To bledne: „W" i „A" to
 * inne oslony, a nie inny material — ich wersje aluminiowe to 30.144WALU
 * i 30.145AALU, wiec kazda z nich ma byc wlasnym trzonem.
 *
 * Lista jest tabela, a nie stala w kodzie, bo tylko uzytkownik wie, ktory
 * sufiks oznacza material, a ktory osobny wyrob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_variant_suffixes', function (Blueprint $table) {
            $table->id();
            $table->string('suffix', 20)->unique();
            $table->timestamps();
        });

        $now = Carbon::now();

        DB::table('production_variant_suffixes')->insert([
            ['suffix' => 'ALU', 'created_at' => $now, 'updated_at' => $now],
            ['suffix' => 'GAL', 'created_at' => $now, 'updated_at' => $now],
            ['suffix' => 'INOX', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Propozycje policzone stara regula sa nieaktualne — trzony sie zmieniaja.
        // Decyzje juz podjete (approved / rejected) zostaja nietkniete.
        $stale = DB::table('production_groups')->where('status', 'proposed')->pluck('id');

        if ($stale->isNotEmpty()) {
            DB::table('production_group_members')->whereIn('group_id', $stale)->delete();
            DB::table('production_groups')->whereIn('id', $stale)->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_variant_suffixes');
    }
};
