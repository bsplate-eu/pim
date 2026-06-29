<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Argo Scope — monitoring konkurencji.
 * scrap_products: znormalizowane oferty z dowolnego źródła (ebay, stahl, allegro...).
 * Klucz matchu między źródłami i z katalogiem PIM: herstellernummer / ean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrap_products', function (Blueprint $table) {
            $table->id();
            $table->string('source')->index();              // 'ebay' | 'stahl' | 'allegro' ...
            $table->string('external_id');                  // itemId eBay / ArtikelNr
            $table->string('seller')->nullable()->index();  // np. scutprotectionsrl
            $table->string('title');
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('herstellernummer')->nullable()->index();   // klucz matchu
            $table->string('ean')->nullable()->index();                // klucz matchu
            $table->string('url', 1024)->nullable();
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('raw')->nullable();                // surowa odpowiedź źródła (audyt)
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrap_products');
    }
};
