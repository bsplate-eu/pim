<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kody ZE ZRODLA odlozone na bok — globalnie dla calego magazynu.
 *
 * Magazyn M3R trzyma nie tylko oslony: sa tam simmeringi, klocki hamulcowe,
 * wykladzina i zabawki. Te pozycje nigdy nie beda mialy pary w PIM, a bez
 * mozliwosci ich odlozenia zakladka „Do zmapowania" jest nie do przejrzenia.
 *
 * Wykluczenie dziala jak w Produkcji: nic nie kasuje, tylko wypycha wiersz
 * z listy roboczej. Przywrocenie oddaje go w tym samym stanie, bo prawda o
 * ilosciach i tak przyjezdza z kazda paczka od nowa.
 *
 * Kluczem jest para (zrodlo, kod zrodlowy) — ten sam symbol moze wystapic
 * i w Subiekcie, i w arkuszu, a decyzja o odlozeniu dotyczy konkretnego zrodla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_code_exclusions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('source_code');
            $table->timestamps();

            $table->unique(['source', 'source_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_code_exclusions');
    }
};
