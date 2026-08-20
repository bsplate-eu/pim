<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domyślny stan katalogu w drzewie skrzynki: zwinięty/rozwinięty.
 * Ustawiany w Ustawieniach (Argo Mail → Katalogi) i stosowany przy wejściu do skrzynki.
 * false = rozwinięty (zachowanie dotychczasowe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_catalogs', function (Blueprint $table) {
            $table->boolean('collapsed')->default(false)->after('sort');
        });
    }

    public function down(): void
    {
        Schema::table('mail_catalogs', function (Blueprint $table) {
            $table->dropColumn('collapsed');
        });
    }
};
