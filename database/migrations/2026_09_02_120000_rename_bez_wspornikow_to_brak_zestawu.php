<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `bez_wspornikow` -> `brak_zestawu`.
 *
 * Znacznik zmienil znaczenie: nie chodzi juz o same wsporniki, tylko o brak
 * calego zestawu. Zmieniamy kolumne, a nie samą etykiete w interfejsie —
 * inaczej kod mowilby jedno, a ekran drugie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->renameColumn('bez_wspornikow', 'brak_zestawu');
        });
    }

    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->renameColumn('brak_zestawu', 'bez_wspornikow');
        });
    }
};
