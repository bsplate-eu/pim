<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('service_class')->nullable();
            $table->json('options')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        \App\Models\Source::updateOrCreate(['name' => 'Sumpguard', 'service_class' => 'SumpguardService'], ['enabled' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
