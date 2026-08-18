<?php

namespace App\Models\Ebay;

use Illuminate\Database\Eloquent\Model;

/**
 * Kategoria eBay „nauczona" w PIM — Marketplace → eBay → Kategorie i parametry.
 * Wzorzec: App\Models\Connect\AllegroCategory z OMS ARGO.
 *
 * `aspects` = migawka wymogów kategorii z Taxonomy API (EbayTaxonomyClient::itemAspectsForCategory):
 *   [ ['name'=>'Hersteller', 'required'=>true, 'mode'=>'FREE_TEXT',
 *      'cardinality'=>'SINGLE', 'value_count'=>5878, 'values'=>[…]] , … ]
 *
 * `aspect_map` = skąd wziąć wartość każdego aspektu przy wystawianiu (klucz = nazwa aspektu):
 *   { "Hersteller":  {"source":"fixed",         "value":"BSP"},
 *     "Herstellernummer": {"source":"product_field", "field":"product_code"},
 *     "Oberflächenbeschaffenheit": {"source":"attribute", "attribute_id":12} }
 *
 * Nazwa aspektu jest kluczem, bo eBay identyfikuje Item Specifics po nazwie (nie po ID) i nazwa
 * jest przetłumaczona per rynek — dlatego mapowanie żyje przy konkretnym (marketplace, category_id).
 */
class EbayCategory extends Model
{
    protected $table = 'ebay_categories';

    protected $guarded = ['id'];

    protected $casts = [
        'leaf' => 'boolean',
        'active' => 'boolean',
        'aspects' => 'array',
        'aspect_map' => 'array',
        'last_synced_at' => 'datetime',
    ];

    /** Źródła wartości aspektu w `aspect_map`. */
    public const SOURCE_FIXED = 'fixed';                 // stała wpisana ręcznie
    public const SOURCE_ATTRIBUTE = 'attribute';         // atrybut produktu PIM
    public const SOURCE_PRODUCT_FIELD = 'product_field'; // kolumna products (product_code, ean, width…)

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', strtoupper($marketplace));
    }

    /** Aspekty, bez których eBay odrzuci ofertę. */
    public function requiredAspects(): array
    {
        return array_values(array_filter($this->aspects ?? [], fn ($a) => ! empty($a['required'])));
    }

    /**
     * Wymagane aspekty, które nie mają jeszcze przypisanego źródła w `aspect_map`.
     * Pusta lista = kategoria gotowa do wystawiania. Używane przez ekran i przez builder szkicu.
     *
     * @return list<string> nazwy aspektów
     */
    public function unmappedRequired(): array
    {
        $map = $this->aspect_map ?? [];

        return array_values(array_map(
            fn (array $a) => $a['name'],
            array_filter($this->requiredAspects(), function (array $a) use ($map) {
                $entry = $map[$a['name']] ?? null;

                if (! is_array($entry) || empty($entry['source'])) {
                    return true;
                }

                // Źródło wskazane, ale bez konkretu (pusta stała / brak atrybutu) = wciąż nieuzupełnione.
                return match ($entry['source']) {
                    self::SOURCE_FIXED => trim((string) ($entry['value'] ?? '')) === '',
                    self::SOURCE_ATTRIBUTE => empty($entry['attribute_id']),
                    self::SOURCE_PRODUCT_FIELD => empty($entry['field']),
                    default => true,
                };
            })
        ));
    }

    public function isReadyForListing(): bool
    {
        return $this->leaf && $this->unmappedRequired() === [];
    }
}
