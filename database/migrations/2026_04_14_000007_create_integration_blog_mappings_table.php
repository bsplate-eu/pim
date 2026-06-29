<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_blog_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->string('external_id', 64);
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_id', 'entity_type', 'entity_id'],
                'uq_blog_mapping'
            );
            $table->index(
                ['integration_id', 'entity_type', 'external_id'],
                'idx_blog_external'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_blog_mappings');
    }
};
