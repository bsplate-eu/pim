<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log automatycznego tłumaczenia (ProductTranslationComposer).
 *
 * Jeden wpis = jedno wywołanie composera dla produktu (ręczne z review, masowe, komenda, skrypt).
 * Trzyma migawkę: co, w jakim locale, z czego na co — do podglądu w UI (Tłumaczenia → Logi).
 */
class TranslationLog extends Model
{
    public const STATUS_OK        = 'ok';        // dopasowano i zapisano zmiany
    public const STATUS_UNMATCHED = 'unmatched'; // brak frazy w matrycy → produkt do review
    public const STATUS_SKIPPED   = 'skipped';   // dopasowano, ale nic nie zmieniono (wszystko zablokowane/aktualne)
    public const STATUS_ERROR     = 'error';     // wyjątek podczas komponowania

    protected $table = 'translation_logs';

    protected $fillable = [
        'product_id',
        'external_id',
        'product_code',
        'name_pl',
        'action',
        'context',
        'status',
        'matched',
        'source_locale',
        'changes',
        'stats',
        'message',
    ];

    protected $casts = [
        'matched' => 'boolean',
        'changes' => 'array',
        'stats'   => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
