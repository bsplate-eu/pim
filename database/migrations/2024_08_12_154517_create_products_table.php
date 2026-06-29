<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_id')->unique();
            $table->string('category');
            $table->string('sub_category');
            $table->text('name');
            $table->string('secondary_name')->nullable();
            $table->string('product_code');
            $table->decimal('price', 10, 2);
            $table->string('year_start', 4);
            $table->string('year_stop', 4)->nullable();
            $table->unsignedDecimal('width')->nullable();
            $table->unsignedDecimal('weight')->nullable();
            $table->boolean('oil')->default(false);
            $table->string('engine');
            $table->string('gearbox')->nullable();
            $table->text('related_products')->nullable();
            $table->text('comment')->nullable();
            $table->json('protection')->nullable();
            $table->json('images')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
