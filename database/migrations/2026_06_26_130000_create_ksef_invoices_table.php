<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Argo HQ → KSeF → faktury (per firma: Pareto / BSP).
 * Lista FV pobranych z KSeF. Na razie wypełniana w trybie demo
 * ("Zaciągnij wszystko" generuje przykładowe FV) — docelowo z API KSeF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('company')->index();          // 'pareto' | 'bsp'
            $table->date('issue_date');                   // Data
            $table->string('number');                     // Nr FV
            $table->string('contractor')->nullable();     // Kontrahent
            $table->text('items_text')->nullable();       // Pozycja FV (za co — pełny opis, hover)
            $table->string('category')->nullable();       // Kategoria (edytowalna)
            $table->date('due_date')->nullable();         // Termin
            $table->decimal('amount', 12, 2)->default(0); // Kwota
            $table->string('currency', 8)->default('PLN');
            $table->string('status', 16)->default('unpaid'); // 'paid' | 'unpaid'
            $table->string('ksef_ref')->nullable();       // numer referencyjny KSeF (pod API)
            $table->string('pdf_path')->nullable();       // ścieżka do PDF (jeśli pobrany)
            $table->string('source', 16)->default('demo'); // 'demo' | 'ksef'
            $table->timestamp('imported_at')->nullable(); // kiedy zaciągnięto z KSeF
            $table->timestamps();

            $table->unique(['company', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_invoices');
    }
};
