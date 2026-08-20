<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Szablony treści aukcji eBay — WŁASNY byt integracji, nie współdzielony z `templates`.
 *
 * Wcześniej eBay pożyczał szablony sklepowe (`templates`, te same, które zasilają Selly,
 * PrestaShop i OpenCart). To był błąd: każda zmiana treści pod eBaya przestawiałaby sklepy,
 * a tuning pod aukcje (limit 80 znaków w tytule, węższy HTML, treść per rynek) jest inny niż
 * pod sklep. Integracja dostaje własne szablony i może je zmieniać, nie ruszając niczego obok.
 *
 * `marketplace` jest kolumną, a nie tabelą wiążącą: szablon należy do JEDNEGO rynku. Ten sam
 * język bywa na dwóch rynkach (DE i AT), ale treść może się różnić — od duplikowania jest
 * przycisk „Kopiuj", który tworzy niezależną kopię do rozjechania.
 *
 * Treść startowa kopiowana z `templates` po locale, żeby po wdrożeniu nie zaczynać od pustych
 * pól. Od tej chwili obie tabele żyją niezależnie.
 */
return new class extends Migration
{
    private const MARKETPLACE_LOCALE = [
        'EBAY_DE' => 'de',
        'EBAY_AT' => 'de',
        'EBAY_FR' => 'fr',
        'EBAY_ES' => 'es',
        'EBAY_IT' => 'it',
        'EBAY_PL' => 'pl',
        'EBAY_GB' => 'en',
    ];

    public function up(): void
    {
        Schema::create('ebay_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // np. „Osłony silnika — DE"
            $table->string('marketplace', 16);            // rynek, do którego szablon należy
            $table->text('title')->nullable();            // szablon tytułu (Blade, limit 80 zn. przy renderze)
            $table->longText('description')->nullable();  // szablon opisu (Blade + HTML)
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['marketplace', 'enabled']);
        });

        $this->seedFromShopTemplates();
        $this->repointSchemes();
    }

    /** Treść startowa: dla każdego rynku bierzemy szablon sklepowy w jego języku. */
    private function seedFromShopTemplates(): void
    {
        if (! Schema::hasTable('templates')) {
            return;
        }

        $now = now();
        $shop = DB::table('templates')->select('id', 'slug', 'locale', 'title', 'description')->get();

        foreach (self::MARKETPLACE_LOCALE as $marketplace => $locale) {
            $candidates = $shop->where('locale', $locale);
            if ($candidates->isEmpty()) {
                continue;
            }

            // Preferujemy szablony naszych własnych kanałów (oslonypareto*, bsp-*) — reszta
            // to szablony obcych sklepów i zaczynanie od nich byłoby losowe.
            $source = $candidates->first(fn ($t) => str_starts_with($t->slug, 'oslonypareto') || str_starts_with($t->slug, 'bsp'))
                ?? $candidates->first();

            DB::table('ebay_templates')->insert([
                'name' => 'Osłony — '.str_replace('EBAY_', '', $marketplace),
                'marketplace' => $marketplace,
                'title' => $source->title,
                'description' => $source->description,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Istniejące schematy wskazywały `templates.id`. Przepinamy je na szablon eBay swojego
     * rynku, żeby konfiguracja zrobiona przed tą zmianą nie wyparowała.
     */
    private function repointSchemes(): void
    {
        if (! Schema::hasTable('ebay_schemes')) {
            return;
        }

        $byMarket = DB::table('ebay_templates')->pluck('id', 'marketplace');

        foreach (DB::table('ebay_schemes')->select('id', 'marketplace')->get() as $scheme) {
            DB::table('ebay_schemes')
                ->where('id', $scheme->id)
                ->update(['template_id' => $byMarket[$scheme->marketplace] ?? null]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_templates');
    }
};
