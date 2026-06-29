<?php

namespace App\Models\Scrap;

use Illuminate\Database\Eloquent\Model;

/**
 * Statystyki pomiaru per kanał konkurenta (Argo Scope). Klucz: source ('stahl', 'sklep2', …).
 * Karmi kafelki monitoringu (nowe/wycofane/ceny) w UI tak samo jak eBay z scrap_ebay_settings.
 */
class ScrapSource extends Model
{
    protected $table = 'scrap_sources';

    protected $guarded = [];

    protected $casts = [
        'last_sync_at' => 'datetime',
        'last_sync_count' => 'integer',
        'prev_offer_count' => 'integer',
        'last_new_count' => 'integer',
        'last_removed_count' => 'integer',
        'last_price_up' => 'integer',
        'last_price_down' => 'integer',
        'last_duration_s' => 'integer',
        'compare_pricelist_id' => 'integer',
        'compare_vat' => 'decimal:2',
        'target_pricelist_id' => 'integer',
    ];
}
