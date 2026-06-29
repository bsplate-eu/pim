<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('translatable_type', 60);
            $table->unsignedBigInteger('translatable_id');
            $table->string('field', 40);
            $table->string('locale', 60);
            $table->string('source', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->useCurrent();
            $table->timestamps();
            $table->unique(
                ['translatable_type', 'translatable_id', 'field', 'locale'],
                'uq_translation_override'
            );
            $table->index(['translatable_type', 'translatable_id'], 'idx_translation_override_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_overrides');
    }
};
