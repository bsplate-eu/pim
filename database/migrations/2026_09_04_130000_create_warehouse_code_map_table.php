<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapowanie kodow: kod w PIM <-> kod po stronie zrodla (Subiekt GT albo arkusz).
 *
 * Kolizje sa pewne — w Subiekcie i w arkuszu te same czesci bywaja pod innymi
 * symbolami niz `products.product_code`. Zamiast zgadywac w locie, trzymamy
 * jawna mape: raz zmapowane zostaje zmapowane, a to, czego dopasowac sie nie
 * dalo, laduje w zakladce „Do zmapowania".
 *
 * WIELE kodow zrodlowych moze wskazywac na JEDEN kod PIM (tak jak w produkcji
 * warianty wchodza w trzon), ale jeden kod zrodlowy nie moze wskazywac na dwa
 * kody PIM — stad unikalnosc po (source, source_code). Bez tego stan
 * rozdwajalby sie po cichu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_code_map', function (Blueprint $table) {
            $table->id();
            $table->string('product_code');
            // 'gt' = Subiekt GT przez ARGO Bridge, 'sheet' = arkusz Google („Tabela").
            $table->string('source', 20);
            $table->string('source_code');
            // Recznie z ekranu czy dopasowane automatem — przy sprzataniu warto
            // wiedziec, czego nie ruszac.
            $table->boolean('manual')->default(true);
            $table->timestamps();

            $table->unique(['source', 'source_code']);
            $table->index(['product_code', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_code_map');
    }
};
