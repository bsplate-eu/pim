<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arkusz inwentury 1:1 — tak, jak wyglada w Excelu, z kolumnami w tej samej
 * kolejnosci. To swiadomie NIE jest znormalizowana tabela stanow: ludzie
 * pracuja na tym arkuszu i musza odnalezc na ekranie dokladnie to, co maja
 * u siebie, razem z pustymi kolumnami i wlasnymi skrotami miejsc.
 *
 * Jeden kod moze lezec w SZESCIU miejscach — arkusz ma cztery opisane pary
 * Miejsce/il. (B/C, D/E, F/G, H/I) i dwie nieopisane (M/N, O/P), z ktorych
 * korzysta jak dotad jeden wiersz (98.041). Pary sa trzymane osobno, a nie
 * zsumowane, bo miejsce jest tu informacja rowna ilosci.
 *
 * `quantity_total` liczymy przy imporcie, zeby lista M3R miala jedna liczbe
 * do kolumny „Tabela" bez sumowania szesciu pol na kazdym odczycie.
 *
 * NULL w ilosci to co innego niz 0: null = pole puste (nikt nie liczyl),
 * 0 = policzone i nie ma. W arkuszu jest 121 kodow z jawnym zerem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_sheet_rows', function (Blueprint $table) {
            $table->id();
            // Nazwa zakladki w skoroszycie — kolejna inwentura wjedzie obok,
            // a nie zamiast.
            $table->string('sheet', 100);
            // Numer wiersza w arkuszu. Przy rozbieznosci ktos musi umiec
            // powiedziec „patrz wiersz 567", a nie szukac kodu oczami.
            $table->unsignedInteger('row_no');
            $table->string('product_code');

            foreach (range(1, 6) as $i) {
                $table->string("place_$i")->nullable();
                $table->integer("qty_$i")->nullable();
            }

            $table->integer('quantity_total')->default(0);
            // Kolumna K arkusza. Naglowek brzmi „steel team", ale w praktyce
            // sa tam uwagi typu „brak srub" — zostawiamy nazwe zrodlowa.
            $table->string('steel_team')->nullable();
            $table->string('uwagi')->nullable();
            // Kolumny opisane w arkuszu, ale puste. Sa tu, bo maja byc
            // widoczne na ekranie i czekac na uzupelnienie.
            $table->string('wymiar')->nullable();
            $table->string('waga')->nullable();
            $table->timestamps();

            $table->unique(['sheet', 'product_code']);
            $table->index('product_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_sheet_rows');
    }
};
