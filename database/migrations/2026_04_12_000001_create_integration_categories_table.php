<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'category_id']);
            $table->index(['integration_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_categories');
    }
};
