<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_postal_codes', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            $table->string('postal_code', 30);
            $table->string('place_name', 180)->nullable();
            $table->string('admin_name1', 100)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->unique(['country_code', 'postal_code'], 'geo_postal_codes_cc_pc_unique');
            $table->index('country_code', 'geo_postal_codes_cc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_postal_codes');
    }
};
