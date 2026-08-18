<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategoria eBay „nauczona" w PIM (Argo Connect → Marketplace → eBay → Kategorie i parametry).
 *
 * Odpowiednik `connect_allegro_categories` z OMS ARGO. Wyszukana po nazwie/ID i aktywowana →
 * trzymamy cache jej aspektów (Item Specifics z Taxonomy API) i mapowanie (`aspect_map`) na nasze
 * atrybuty PIM. Aktywne kategorie zasilają dropdown w schemacie wystawiania.
 *
 * Klucz to (marketplace, category_id), NIE samo category_id: kategoria „osłony silnika" ma inne ID
 * i inne drzewo na każdym rynku (DE 14769/drzewo 77, FR 9886/drzewo 71), a nazwy aspektów są
 * przetłumaczone — DE „Hersteller", FR „Marque". Jeden wiersz = jedna kategoria na jednym rynku.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_categories', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace', 16);                  // EBAY_DE / EBAY_FR / EBAY_ES…
            $table->string('category_id');                      // ID kategorii w REST API (np. 14769)
            $table->string('category_name')->nullable();        // cache nazwy (w języku rynku)
            $table->string('category_path')->nullable();        // ścieżka od korzenia (podgląd w UI)
            $table->string('category_tree_id')->nullable();     // drzewo rynku (DE=77, FR=71)
            $table->boolean('leaf')->default(true);             // tylko w liściu można wystawiać
            $table->boolean('active')->default(false);          // „nauczona" — widoczna w schematach
            $table->json('aspects')->nullable();                // cache z Taxonomy: name/required/mode/cardinality/values
            $table->json('aspect_map')->nullable();             // aspekt → źródło wartości (patrz EbayCategory)
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['marketplace', 'category_id']);
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_categories');
    }
};
