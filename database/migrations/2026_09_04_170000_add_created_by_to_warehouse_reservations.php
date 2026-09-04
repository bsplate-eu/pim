<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rozdzielenie „DLA kogo” od „KTO wpisal”.
 *
 * Na magazynie jeden czlowiek stoi przy terminalu i odklada towar dla kilku
 * osob naraz. Dopoki rezerwacja szla sztywno na zalogowanego, wszystko ladowalo
 * na niego — i po tygodniu nie bylo wiadomo, dla kogo naprawde lezy paczka.
 *
 * `user_id`/`user_name` zostaja tym, DLA KOGO jest rezerwacja (to widac na
 * ekranie), a nowe pola mowia, KTO ja wpisal (to widac w dzienniku).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_id')->nullable()->after('user_name');
            $table->string('created_by_name')->nullable()->after('created_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_reservations', function (Blueprint $table) {
            $table->dropColumn(['created_by_id', 'created_by_name']);
        });
    }
};
