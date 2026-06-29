<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Miesiące planera kosztów (np. Styczeń 2026).
        Schema::create('cost_planner_months', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('label');
            $table->text('notes')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['year', 'month']);
        });

        // Pozycje kosztowe w ramach miesiąca.
        Schema::create('cost_planner_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_planner_month_id')
                ->constrained('cost_planner_months')
                ->cascadeOnDelete();

            $table->string('name')->nullable();                          // KOSZTY
            $table->decimal('amount', 12, 2)->default(0);                // DO ZAPŁATY
            $table->enum('status', ['paid', 'unpaid'])->default('unpaid'); // STATUS
            $table->date('due_date')->nullable();                        // DO KIEDY
            $table->string('category', 32)->nullable();                  // RODZAJ
            $table->string('type', 16)->nullable();                      // TYP (stale/zmienne)
            $table->string('invoice_path')->nullable();                  // FAKTURA (ścieżka pliku)
            $table->string('invoice_name')->nullable();
            $table->char('currency', 3)->default('PLN');                 // WALUTA
            $table->decimal('formula_amount', 12, 2)->nullable();        // FORMUŁA
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['cost_planner_month_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_planner_items');
        Schema::dropIfExists('cost_planner_months');
    }
};
