<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('baselinker_order_product_id')->nullable();

            $table->string('storage', 20)->nullable();
            $table->unsignedBigInteger('storage_id')->nullable();
            $table->string('product_id', 50)->nullable();
            $table->string('variant_id', 50)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('ean', 50)->nullable();
            $table->string('location', 100)->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('auction_id', 50)->nullable();
            $table->string('attributes', 500)->nullable();
            $table->decimal('price_brutto', 14, 2)->default(0);
            $table->decimal('tax_rate', 6, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('weight', 10, 3)->default(0);
            $table->unsignedBigInteger('bundle_id')->nullable();

            $table->timestamps();

            $table->index('sku');
            $table->index('ean');
            $table->index('baselinker_order_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_products');
    }
};
