<?php

namespace App\Models\Scrap;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Oferta konkurenta z dowolnego źródła (Argo Scope).
 * Match między źródłami i z katalogiem PIM po herstellernummer / ean.
 */
class ScrapProduct extends Model
{
    protected $fillable = [
        'source',
        'external_id',
        'seller',
        'product_id',
        'match_type',
        'title',
        'price',
        'individual_price',
        'excluded',
        'currency',
        'herstellernummer',
        'ean',
        'url',
        'first_seen',
        'last_seen',
        'is_active',
        'raw',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected $casts = [
        'price' => 'decimal:2',
        'individual_price' => 'decimal:2',
        'excluded' => 'boolean',
        'is_active' => 'boolean',
        'first_seen' => 'datetime',
        'last_seen' => 'datetime',
        'raw' => 'array',
    ];
}
