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

        \Illuminate\Support\Facades\DB::statement("UPDATE products SET category = CONCAT(category, '/', sub_category) WHERE sub_category IS NOT NULL AND sub_category != ''");

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sub_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sub_category')->nullable();
        });
    }
};
