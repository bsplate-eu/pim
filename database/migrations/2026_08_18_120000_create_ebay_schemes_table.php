<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schemat wystawiania na eBay — przepis „jak wystawić produkt na tym rynku w tej kategorii".
 * Odpowiednik `connect_allegro_schemes` z OMS ARGO (tam nazwany „schemat" zamiast „agent").
 *
 * Spina etap A (kategoria z aspektami) z etapem B (szablon treści) i dokłada cenę oraz
 * bezpiecznik publikacji. Pracownik przy wystawianiu wybiera produkty + schemat — reszta
 * dzieje się sama.
 *
 * `marketplace` jest na schemacie, a nie wyprowadzany z kategorii, bo rządzi trzema rzeczami
 * naraz: kategorią (inne ID i drzewo na każdym rynku), locale szablonu i stawką VAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                  // np. „Osłony silnika DE"
            $table->string('marketplace', 16);                       // EBAY_DE / EBAY_FR / EBAY_ES…

            // Etap A: nauczona kategoria (z aspektami i mapowaniem). Skasowanie kategorii nie
            // może po cichu zmienić znaczenia schematu — stąd nullOnDelete + walidacja przy publikacji.
            $table->foreignId('ebay_category_id')->nullable()->constrained('ebay_categories')->nullOnDelete();

            // Etap B: szablon treści (tabela `templates`, ten sam co dla sklepów). Bez FK-constraintu,
            // bo `templates` bywa czyszczone/importowane niezależnie — pilnuje tego walidacja.
            $table->unsignedBigInteger('template_id')->nullable();

            // Cena: cennik PIM (netto) × mnożnik × (1 + VAT). VAT domyślnie 19 — najczęstszy rynek to DE.
            $table->unsignedBigInteger('pricelist_id')->nullable();
            $table->decimal('price_multiplier', 8, 4)->default(1);
            $table->decimal('tax_percent', 5, 2)->default(19);
            $table->unsignedInteger('default_stock')->default(5);

            // Polityki eBay (business policies) — do wypełnienia w etapie E, gdy będzie OAuth.
            // Trzymamy je jako ID-tekstowe, bo tak zwraca je eBay i tak wchodzą do payloadu oferty.
            $table->string('fulfillment_policy_id')->nullable();
            $table->string('payment_policy_id')->nullable();
            $table->string('return_policy_id')->nullable();
            $table->string('merchant_location_key')->nullable();     // lokalizacja nadania

            // BEZPIECZNIK: domyślnie oferta powstaje jako szkic i ktoś musi ją świadomie włączyć.
            $table->string('publication_mode', 10)->default('draft'); // draft | active
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['marketplace', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_schemes');
    }
};
