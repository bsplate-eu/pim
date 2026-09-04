<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surowe stany ZE ZRODLA — tak, jak przyszly, bez tlumaczenia na kody PIM.
 *
 * Trzymamy je osobno od `products` celowo: zrodlo ma wlasne symbole i wlasna
 * prawde. Dopiero widok Magazynu zestawia je z katalogiem — po zgodnym kodzie
 * albo po recznym mapowaniu (`warehouse_code_map`). Dzieki temu wiersz, ktory
 * do niczego nie pasuje, nie znika po cichu, tylko laduje w „Do zmapowania".
 *
 * Kazda paczka to PELNY stan magazynu, nie roznica — czego w niej nie ma, tego
 * na magazynie nie ma. Stad `synced_at`: po zapisie kasujemy wiersze starsze
 * niz biezaca paczka, wiec towar zdjety w ERP znika tez u nas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_source_stock', function (Blueprint $table) {
            $table->id();
            // 'gt' = Subiekt GT przez ARGO Bridge, 'sheet' = arkusz Google.
            $table->string('source', 20);
            $table->string('source_code');
            // Nazwa po stronie zrodla — pomaga rozpoznac wiersz, ktorego w PIM nie ma.
            $table->string('name')->nullable();
            // Ulamki, bo GT potrafi trzymac stany w metrach i kilogramach.
            $table->decimal('quantity', 14, 3)->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_code']);
            $table->index('source_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_source_stock');
    }
};
