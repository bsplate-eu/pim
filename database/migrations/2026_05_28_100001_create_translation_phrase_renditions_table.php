<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_phrase_renditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_phrase_id')->constrained('translation_phrases')->cascadeOnDelete();
            $table->string('channel', 60);
            $table->text('value');
            $table->string('source', 20)->default('sheet_import');
            $table->unsignedInteger('variants_count')->default(1);
            $table->timestamps();
            $table->unique(['translation_phrase_id', 'channel'], 'uq_phrase_channel');
            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_phrase_renditions');
    }
};
