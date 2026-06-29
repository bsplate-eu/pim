<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connect_base_settings', function (Blueprint $table) {
            $table->string('label', 80)->nullable()->after('id');
        });

        // Backfill: istniejący singleton → "Argo"
        $existing = DB::table('connect_base_settings')->orderBy('id')->get();
        foreach ($existing as $i => $row) {
            DB::table('connect_base_settings')
                ->where('id', $row->id)
                ->update(['label' => $i === 0 ? 'Argo' : ('Base #' . $row->id)]);
        }

        Schema::table('connect_base_settings', function (Blueprint $table) {
            $table->string('label', 80)->nullable(false)->change();
            $table->unique('label');
        });
    }

    public function down(): void
    {
        Schema::table('connect_base_settings', function (Blueprint $table) {
            $table->dropUnique(['label']);
            $table->dropColumn('label');
        });
    }
};
