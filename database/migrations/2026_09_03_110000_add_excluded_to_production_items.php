<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wykluczenie kodu z tabeli produkcji.
 *
 * Kod wykluczony nie jest wierszem i nie liczy sie do niczego: ani do sumy
 * grupy, ani do etapow, ani do barometru. Nie kasujemy przy tym zadnych danych
 * — odznaczenie przywraca go w tym samym stanie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->boolean('excluded')->default(false)->index()->after('stage_id');
        });
    }

    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropColumn('excluded');
        });
    }
};
