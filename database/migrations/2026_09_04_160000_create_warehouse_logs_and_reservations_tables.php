<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dwie rzeczy, ktore magazyn musi umiec powiedziec o sobie sam:
 * KTO co zrobil (logi) i CO jest odlozone dla kogo (rezerwacje).
 *
 * Logi sa wspolne dla calego dzialu — jedna tabela na wszystkie ekrany,
 * z kolumna `area`. Osobne dzienniki per zakladka rozjechalyby sie przy
 * pierwszej akcji, ktora dotyka dwoch ekranow naraz (mapowanie robione
 * w Tabeli zmienia liste M3R).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_logs', function (Blueprint $table) {
            $table->id();
            // Bez klucza obcego SWIADOMIE. Panel loguje `admin_users`, sklep
            // `users` — a wpis w dzienniku nie moze sie nie zapisac dlatego, ze
            // autor jest z innej tabeli albo konta juz nie ma.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // Nazwe zapisujemy OBOK id: log ma przezyc skasowanie konta,
            // bo „user #14 zrobil" po roku nikomu nic nie mowi.
            $table->string('user_name')->nullable();
            // Z ktorego ekranu przyszla akcja: m3r, tabela, ustawienia, mobile, import, bridge.
            $table->string('area', 20);
            // Co to bylo: cell.update, map.store, map.destroy, reservation.create...
            $table->string('action', 40);
            // Czego dotyczylo — kod ze zrodla i (jesli znany) kod w PIM.
            $table->string('source_code')->nullable();
            $table->string('product_code')->nullable();
            // Gotowe zdanie po polsku. Log ma sie czytac bez odszyfrowywania
            // pola `meta` — to ostatnia rzecz, ktora ktos otwiera po awarii.
            $table->string('description', 500);
            // PRZED i PO. Bez tego „zmieniono ilosc" nie mowi z czego na co.
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['area', 'created_at']);
            $table->index('source_code');
        });

        Schema::create('warehouse_reservations', function (Blueprint $table) {
            $table->id();
            // 'sheet' = pozycja z arkusza (Tabela). Zostawiamy miejsce na 'gt',
            // gdy rezerwowac bedzie mozna wprost na stanie z Subiekta.
            $table->string('source', 20)->default('sheet');
            $table->string('source_code');
            // Kod PIM w momencie rezerwacji — zapisany, a nie liczony w locie,
            // bo mapowanie moze sie pozniej zmienic.
            $table->string('product_code')->nullable();
            $table->unsignedInteger('quantity');
            // Bez klucza obcego z tego samego powodu co w logach — autor jest
            // z `admin_users`, a nie z `users`.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('note')->nullable();
            // Zwolniona rezerwacja zostaje w bazie — znika z ekranu, ale nie
            // z historii. Kasowanie wiersza zabraloby odpowiedz na pytanie
            // „kto to trzymal przez tydzien".
            $table->timestamp('released_at')->nullable();
            $table->string('released_by')->nullable();
            $table->timestamps();

            $table->index(['source', 'source_code', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_reservations');
        Schema::dropIfExists('warehouse_logs');
    }
};
