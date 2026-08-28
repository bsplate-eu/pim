<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Gotowe" — czwarty element linii etapow (po Etap 1/2/3). Wyklucza sie z nimi
 * tak samo jak one miedzy soba, wiec wchodzi do podzialu procentowego.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->boolean('gotowe')->default(false)->index()->after('etap_3');
        });
    }

    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropColumn('gotowe');
        });
    }
};
