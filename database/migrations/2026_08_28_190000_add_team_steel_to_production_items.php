<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drugi znacznik produkcyjny — „Team Steel". Dziala tak samo jak „Projekt":
 * oznaczenie per kod, wlasna zakladka filtrowania.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->boolean('team_steel')->default(false)->index()->after('has_project');
        });
    }

    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropColumn('team_steel');
        });
    }
};
