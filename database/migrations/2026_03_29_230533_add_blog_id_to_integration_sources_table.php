<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_sources', function (Blueprint $table) {
            // FUTURE-PROOF: kolumna bez FK do `blogs` — tabela/modul Blog jeszcze nie istnieje.
            // Gdy modul Blog powstanie, dodaj osobna migracje:
            //   $table->foreign('blog_id')->references('id')->on('blogs')->nullOnDelete();
            // oraz odkomentuj relacje blog() w App\Models\IntegrationSource
            // i 'blogOptions' w App\Http\Controllers\Admin\IntegrationController.
            $table->unsignedBigInteger('blog_id')->nullable()->after('pricelist_id');
        });
    }

    public function down(): void
    {
        Schema::table('integration_sources', function (Blueprint $table) {
            $table->dropColumn('blog_id');
        });
    }
};
