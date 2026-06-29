<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Argo HQ → KSeF → Ustawienia → kategorie (per firma).
 * Lista kategorii edytowana w zakładce „Ustawienia"; zasila datalist edycji kategorii FV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_categories', function (Blueprint $table) {
            $table->id();
            $table->string('company')->index();   // 'pareto' | 'bsp'
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['company', 'name']);
        });

        // Domyślne kategorie dla obu firm.
        $defaults = ['Sprzedaż', 'Usługi', 'Towary', 'Transport', 'Inne'];
        $now = now();
        $rows = [];
        foreach (['pareto', 'bsp'] as $company) {
            foreach ($defaults as $i => $name) {
                $rows[] = [
                    'company' => $company,
                    'name' => $name,
                    'position' => $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('ksef_categories')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_categories');
    }
};
