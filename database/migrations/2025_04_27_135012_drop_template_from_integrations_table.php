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
        Schema::table('integrations', function (Blueprint $table) {

            $table->foreignId('category_id')->after('id')->nullable()->constrained('categories')->cascadeOnDelete();

            $table->dropForeign(['template_id']);
            $table->dropForeign(['pricelist_id']);

            $table->dropColumn('template_id');
            $table->dropColumn('pricelist_id');
            $table->dropColumn('tax');
            $table->dropColumn('multiplier');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->foreignId('template_id')->constrained('templates')->cascadeOnDelete();
            $table->foreignId('pricelist_id')->constrained('pricelists')->cascadeOnDelete();
            $table->unsignedSmallInteger('tax')->default(23);
            $table->unsignedDecimal('multiplier', 5, 2)->default(1);
        });
    }
};
