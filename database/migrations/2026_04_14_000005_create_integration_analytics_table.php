<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->string('external_id', 64);
            $table->date('date');
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('unique_views')->default(0);
            $table->timestamps();

            $table->unique(
                ['integration_id', 'entity_type', 'entity_id', 'date'],
                'uq_analytics_day'
            );
            $table->index(
                ['integration_id', 'date'],
                'idx_analytics_date'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_analytics');
    }
};
