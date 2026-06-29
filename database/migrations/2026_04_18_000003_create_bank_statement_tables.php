<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_months', function (Blueprint $table) {
            $table->id();
            $table->string('bank', 32);            // santander | pko
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('label');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['bank', 'year', 'month']);
        });

        Schema::create('bank_statement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_month_id')
                ->constrained('bank_statement_months')
                ->cascadeOnDelete();

            $table->date('booking_date')->nullable();
            $table->text('description')->nullable();
            $table->string('counterparty')->nullable();
            $table->decimal('amount', 14, 2);               // ujemne = obciążenie
            $table->enum('direction', ['in', 'out']);
            $table->string('reference')->nullable();
            $table->json('raw_row')->nullable();

            $table->boolean('is_important')->default(true);
            $table->string('settlement_group', 16)->nullable();  // koszt | kasa | null

            // Polimorficzny match
            $table->string('matched_type')->nullable();
            $table->unsignedBigInteger('matched_id')->nullable();

            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['bank_statement_month_id', 'position']);
            $table->index(['matched_type', 'matched_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_items');
        Schema::dropIfExists('bank_statement_months');
    }
};
