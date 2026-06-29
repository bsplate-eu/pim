<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricelistProduct extends Model
{
    protected $table = 'pricelist_product';

    /**
     * Wyrazenie SQL ceny eksportowej: cena reczna (manual_price) gdy > 0, inaczej cena wlasciwa (price).
     * "Cena netto aut." (auto_price) NIE jest eksportowana — wpada do price przyciskiem "Przepisz".
     */
    public const EXPORT_PRICE_SQL = 'COALESCE(NULLIF(manual_price, 0), price)';

    /**
     * Mapa product_id => cena eksportowa dla jednego cennika.
     * Zamiennik na ->where('pricelist_id',$id)->pluck('price','product_id') w sciezkach eksportu.
     */
    public static function exportPriceMap(int|string $pricelistId): \Illuminate\Support\Collection
    {
        return static::query()
            ->where('pricelist_id', $pricelistId)
            ->selectRaw('product_id, ' . self::EXPORT_PRICE_SQL . ' as price')
            ->pluck('price', 'product_id');
    }
}
