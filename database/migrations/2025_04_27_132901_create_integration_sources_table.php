<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('integration_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('templates')->cascadeOnDelete();
            $table->foreignId('pricelist_id')->constrained('pricelists')->cascadeOnDelete();
            $table->unsignedSmallInteger('tax')->default(23);
            $table->unsignedDecimal('multiplier', 5, 2)->default(1);
            $table->timestamps();
        });

        Schema::table('integration_products', function (Blueprint $table) {
            $table->foreignId('integration_source_id')->nullable()->after('id')->constrained('integration_sources')->cascadeOnDelete();
        });

        $sources = \App\Models\Source::all();
        $integrations = \App\Models\Integration::all();
        foreach ($sources as $source) {
            foreach ($integrations as $integration) {
                $is = \App\Models\IntegrationSource::updateOrcreate([
                    'integration_id' => $integration->id,
                    'source_id' => $source->id,
                ], [
                    'template_id' => $integration->template_id,
                    'pricelist_id' => $integration->pricelist_id,
                    'tax' => $integration->tax,
                    'multiplier' => $integration->multiplier
                ]);

                \Illuminate\Support\Facades\DB::table('integration_products')
                    ->join('products', 'integration_products.product_id', '=', 'products.id')
                    ->where('integration_products.integration_id', $integration->id)
                    ->where('products.source_id', $source->id)
                    ->update(['integration_products.integration_source_id' => $is->id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_sources');
    }
};
