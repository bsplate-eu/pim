<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migawka fitmentu (kompatybilności pojazdów / kType) przy ofercie.
 *
 * Do tej pory stan fitmentu żył wyłącznie w datowanych plikach `storage/app/ebay/compat-audit-*.json`
 * (komenda ebay:compat-audit). Ekran „kType" musi pokazać pokrycie od razu przy liście aukcji,
 * a jeden `GetItem` na wiersz to jedno wywołanie API — przy 50 wierszach strona wstawałaby minutę.
 * Trzymamy więc licznik przy ofercie i odświeżamy go na żądanie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ebay_offers', function (Blueprint $table) {
            $table->unsignedInteger('compat_count')->nullable()->after('listing_url');   // ile wpisów fitmentu
            $table->timestamp('compat_checked_at')->nullable()->after('compat_count');   // kiedy sprawdzone
        });
    }

    public function down(): void
    {
        Schema::table('ebay_offers', function (Blueprint $table) {
            $table->dropColumn(['compat_count', 'compat_checked_at']);
        });
    }
};
