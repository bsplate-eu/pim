<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_media_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('media_id');
            $table->string('external_product_id', 64)->nullable();
            $table->string('action', 20)->default('upload');
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('source_url', 512)->nullable();
            $table->string('md5_hash', 32)->nullable();
            $table->string('state', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_id', 'product_id', 'media_id', 'action'],
                'uq_media_item'
            );
            $table->index(
                ['integration_id', 'state', 'priority'],
                'idx_media_pending'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_media_queue');
    }
};
