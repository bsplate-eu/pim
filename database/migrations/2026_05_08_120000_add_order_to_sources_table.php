<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->unsignedInteger('order')->default(0)->after('enabled')->index();
        });

        // Populate kolejność startową = po id (zachowuje obecny układ na liście)
        DB::table('sources')->orderBy('id')->get()->values()->each(function ($row, $index) {
            DB::table('sources')->where('id', $row->id)->update(['order' => $index]);
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }
};
