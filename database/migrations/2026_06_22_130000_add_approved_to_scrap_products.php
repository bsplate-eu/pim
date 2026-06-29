<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trwały stan „Zatwierdź" oferty — Argo Scope → Rumuni.
 * NULL = niedotknięte (UI pokazuje auto-domyślne, np. zielone); 1/0 = ręczny wybór użytkownika.
 * Dzięki temu zaznaczenia przeżywają odświeżenie strony.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_products', function (Blueprint $table) {
            $table->boolean('approved')->nullable()->after('individual_price');
        });
    }

    public function down(): void
    {
        Schema::table('scrap_products', function (Blueprint $table) {
            $table->dropColumn('approved');
        });
    }
};
