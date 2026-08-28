<?php

use App\Services\Production\SubiektSalesImport;
use Illuminate\Database\Migrations\Migration;

/**
 * Przeladowanie sprzedazy 12M z PELNEGO raportu Subiekta.
 *
 * Poprzedni plik byl zawezony do grupy „Oslony stalowe" i gubil dokumenty
 * zaksiegowane pod inna grupa — szesc kodow mialo zanizona ilosc
 * (09.066 47->52, 15.094 24->26, 09.068 17->19, 10.091 35->36,
 *  14.097 13->14, 40.102 78->79).
 *
 * Nowy plik: grupa (dowolna), 504 pozycje / 7375 szt. Pozycja
 * „(Usluga jednorazowa)" (2480 szt.) nie jest produktem i nie ma symbolu —
 * nie ma jej w CSV. Zostaje 503 kody / 4895 szt.
 *
 * Do tego alias `06.048 DD` -> `06.048` (patrz SubiektSalesImport::ALIASES),
 * wiec 06.048 dostaje 37 + 100 = 137 szt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('data/sprzedaz-subiekt-2026-08-v2.csv');

        if (! is_file($path)) {
            return;
        }

        (new SubiektSalesImport())->run($path);
    }

    public function down(): void
    {
        // Wstecz wraca poprzedni plik, zeby rollback nie zostawil pustej kolumny.
        $path = database_path('data/sprzedaz-subiekt-2026-08.csv');

        if (is_file($path)) {
            (new SubiektSalesImport())->run($path);
        }
    }
};
