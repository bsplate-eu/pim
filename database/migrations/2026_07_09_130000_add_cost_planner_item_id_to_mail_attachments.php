<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Powiązanie załącznika e-maila (faktury) z pozycją kosztu w Planerze.
 * Ustawiane po „Dodaj do kosztów" w Argo Mail → daje zielony ptaszek „w kosztach"
 * i chroni przed ponownym (zdublowanym) importem tej samej faktury.
 * Kasacja pozycji kosztu → NULL (ptaszek znika, można dodać ponownie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_attachments', function (Blueprint $table) {
            $table->foreignId('cost_planner_item_id')->nullable()->after('size')
                ->constrained('cost_planner_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mail_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_planner_item_id');
        });
    }
};
