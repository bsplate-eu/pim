<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_dictionary', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('baselinker_status_id')->unique();
            $table->string('name', 150);
            $table->string('name_for_customer', 150)->nullable();
            $table->string('color', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_dictionary');
    }
};
