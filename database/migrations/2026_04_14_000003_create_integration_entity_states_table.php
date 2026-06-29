<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_entity_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('connector', 20);
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->string('external_id', 64)->nullable();
            $table->string('state', 20)->default('pending');
            $table->string('payload_hash', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_id', 'connector', 'entity_type', 'entity_id'],
                'uq_entity_state'
            );
            $table->index(
                ['integration_id', 'connector', 'state'],
                'idx_state_connector'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_entity_states');
    }
};
