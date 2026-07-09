<?php

namespace App\Models\Ebay;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nasza oferta (wariant) na eBay — pobrana z konta sprzedawcy przez Sell/Trading API.
 * Mapowanie do katalogu PIM po SKU (sku ↔ Product.product_code), jak w Argo Scope.
 */
class EbayOffer extends Model
{
    protected $fillable = [
        'item_id',
        'sku',
        'marketplace',
        'title',
        'price',
        'currency',
        'quantity',
        'quantity_sold',
        'listing_status',
        'listing_url',
        'variation',
        'product_id',
        'match_type',
        'raw',
        'first_seen',
        'last_seen',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'quantity_sold' => 'integer',
        'variation' => 'array',
        'raw' => 'array',
        'first_seen' => 'datetime',
        'last_seen' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
