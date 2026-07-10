<?php

namespace App\Models\Ebay;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log automatycznej akcji eBay (na razie: auto-restock).
 *
 * Jeden wpis = jedna oferta, którą reguła podniosła (lub próbowała podnieść) — z migawką
 * tytułu/SKU/rynku i stanem PRZED→PO. Podgląd w UI: Marketplace → Ebay → zakładka „Logi".
 */
class EbayActionLog extends Model
{
    public const ACTION_AUTO_RESTOCK = 'auto_restock'; // stan 0 → docelowy (ReviseInventoryStatus)
    public const ACTION_AUTO_ASSIGN  = 'auto_assign';  // mapowanie oferty → produkt po SKU

    public const STATUS_OK    = 'ok';    // akcja wykonana
    public const STATUS_ERROR = 'error'; // wyjątek podczas wykonania

    public const CONTEXT_CRON   = 'cron';   // z crona (ebay:auto-actions)
    public const CONTEXT_MANUAL = 'manual'; // „Uruchom teraz"
    public const CONTEXT_SYNC   = 'sync';   // po pobraniu ofert (ebay:sync-offers)

    protected $table = 'ebay_action_logs';

    protected $fillable = [
        'action',
        'context',
        'status',
        'marketplace',
        'item_id',
        'sku',
        'title',
        'listing_url',
        'product_id',
        'qty_before',
        'qty_after',
        'message',
    ];

    protected $casts = [
        'qty_before' => 'integer',
        'qty_after'  => 'integer',
        'product_id' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
