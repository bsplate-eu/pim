<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pricelists', function (Blueprint $table) {
            // Reczna kolejnosc cennikow na liscie (1..N), ustawiana dropdownem "Kolejnosc".
            $table->unsignedInteger('position')->default(0)->index();
        });

        // Backfill: dotychczasowa kolejnosc (wg id) staje sie pozycja startowa.
        $position = 0;
        foreach (DB::table('pricelists')->orderBy('id')->pluck('id') as $id) {
            DB::table('pricelists')->where('id', $id)->update(['position' => ++$position]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricelists', function (Blueprint $table) {
            $table->dropIndex(['position']);
            $table->dropColumn('position');
        });
    }
};
