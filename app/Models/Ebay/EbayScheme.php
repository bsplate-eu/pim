<?php

namespace App\Models\Ebay;

use App\Models\Pricelist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schemat wystawiania na eBay — wzorzec: App\Models\Connect\AllegroScheme z OMS ARGO.
 *
 * Przepis per (rynek, kategoria): co wystawiamy, z jakiej kategorii, jakim szablonem treści,
 * po jakiej cenie i czy od razu aktywne. Pracownik przy wystawianiu wybiera produkty + schemat.
 */
class EbayScheme extends Model
{
    protected $table = 'ebay_schemes';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'price_multiplier' => 'float',
        'tax_percent' => 'float',
        'default_stock' => 'integer',
    ];

    public const MODE_DRAFT = 'draft';
    public const MODE_ACTIVE = 'active';

    /**
     * Rynek → locale treści. Rządzi tym, który szablon z `templates` pasuje do schematu:
     * aukcja na EBAY_FR ma mieć opis po francusku. AT mówi po niemiecku, GB po angielsku.
     */
    public const MARKETPLACE_LOCALE = [
        'EBAY_DE' => 'de',
        'EBAY_AT' => 'de',
        'EBAY_FR' => 'fr',
        'EBAY_ES' => 'es',
        'EBAY_IT' => 'it',
        'EBAY_PL' => 'pl',
        'EBAY_GB' => 'en',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(EbayCategory::class, 'ebay_category_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EbayTemplate::class, 'template_id');
    }

    public function pricelist(): BelongsTo
    {
        return $this->belongsTo(Pricelist::class, 'pricelist_id');
    }

    /** Locale treści dla rynku schematu (fallback: en). */
    public function locale(): string
    {
        return self::MARKETPLACE_LOCALE[strtoupper((string) $this->marketplace)] ?? 'en';
    }

    /** Cena brutto z ceny netto cennika: × mnożnik × (1 + VAT). */
    public function grossPrice(float $net): float
    {
        return round($net * ($this->price_multiplier ?: 1) * (1 + ($this->tax_percent ?: 0) / 100), 2);
    }

    /**
     * Czego brakuje, żeby tym schematem dało się wystawić. Pusta lista = komplet.
     *
     * Świadomie NIE sprawdzamy tu polityk eBay (fulfillment/payment/return) ani lokalizacji —
     * te są wymagane dopiero przy AKTYWACJI oferty, a szkic przejdzie bez nich. Blokowanie
     * ich brakiem uniemożliwiłoby to, po co szkice w ogóle są: złożenie oferty i obejrzenie
     * jej, zanim domkniemy konfigurację konta.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if (! $this->category) {
            $problems[] = 'brak kategorii eBay';
        } elseif ($this->category->marketplace !== $this->marketplace) {
            // Kategoria z innego rynku = inne drzewo i inne nazwy aspektów. eBay odrzuciłby ofertę.
            $problems[] = "kategoria jest z rynku {$this->category->marketplace}, a schemat z {$this->marketplace}";
        } elseif (($missing = $this->category->unmappedRequired()) !== []) {
            $problems[] = 'kategoria ma niezmapowane wymagane aspekty: '.implode(', ', $missing);
        }

        if (! $this->template) {
            $problems[] = 'brak szablonu treści';
        } elseif ($this->template->marketplace !== $this->marketplace) {
            // Szablon eBay należy do jednego rynku. Wzięcie cudzego dałoby aukcję w złym języku
            // (albo z zapisami prawnymi innego kraju), a eBay tego nie wyłapie za nas.
            $problems[] = "szablon „{$this->template->name}” należy do rynku {$this->template->marketplace}, a schemat jest z {$this->marketplace}";
        } elseif (! $this->template->enabled) {
            $problems[] = "szablon „{$this->template->name}” jest wyłączony";
        }

        if (! $this->pricelist_id) {
            $problems[] = 'brak cennika PIM (źródło ceny)';
        }

        return $problems;
    }

    public function isReady(): bool
    {
        return $this->problems() === [];
    }

    /** Czy schemat wystawia od razu aktywne oferty (zamiast szkiców). */
    public function publishesActive(): bool
    {
        return $this->publication_mode === self::MODE_ACTIVE;
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
