<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupowanie wariantow kodu pod jeden wiersz produkcji.
 *
 * Propozycje wylicza automat (kod bez koncowych liter = trzon), ale nic nie
 * dziala samo: grupa zaczyna obowiazywac dopiero po zatwierdzeniu w Ustawieniach.
 * W propozycji mozna odznaczyc skladnik — odznaczony zostaje osobnym wierszem.
 *
 * `production_groups.trunk`  — kod glowy wiersza (zawsze istnieje jako produkt).
 * `production_group_members` — WYLACZNIE warianty; trzon nie ma tu wiersza,
 *                              bo z definicji jest w grupie i nie da sie go odpiac.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_groups', function (Blueprint $table) {
            $table->id();
            $table->string('trunk')->unique();
            // proposed = czeka na decyzje, approved = grupuje, rejected = nie proponuj ponownie
            $table->string('status', 20)->default('proposed')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('production_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('production_groups')->cascadeOnDelete();
            // Kod moze nalezec do jednej grupy — stad unikat, nie para (group_id, code).
            $table->string('product_code')->unique();
            $table->boolean('included')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_group_members');
        Schema::dropIfExists('production_groups');
    }
};
