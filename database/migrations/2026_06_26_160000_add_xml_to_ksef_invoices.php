<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Przechowujemy pełny XML faktury (z eksportu KSeF), żeby PDF generować z bazy
 * bez ponownego odpytywania KSeF (a tym samym bez ryzyka rate-limitu na klik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_invoices', function (Blueprint $table) {
            $table->longText('xml')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('ksef_invoices', function (Blueprint $table) {
            $table->dropColumn('xml');
        });
    }
};
