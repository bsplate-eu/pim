<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_phrases', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 200)->unique();
            $table->text('phrase_pl');
            $table->unsignedInteger('product_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_phrases');
    }
};
