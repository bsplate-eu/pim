<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wiersz danych produkcyjnych przypiety do kodu (`products.product_code`).
 * Powstaje leniwie — dopiero gdy ktos cos na kodzie oznaczy albo gdy kod
 * dostanie etap z przeliczenia.
 */
class ProductionItem extends Model
{
    protected $table = 'production_items';

    protected $fillable = [
        'product_code',
        'stage_id',
        'has_project',
        'team_steel',
        'brak_zestawu',
        'projekty_gotowe',
        'sales_12m',
    ];

    protected $casts = [
        'has_project' => 'boolean',
        'team_steel' => 'boolean',
        'brak_zestawu' => 'boolean',
        'projekty_gotowe' => 'boolean',
        'sales_12m' => 'integer',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProductionStage::class, 'stage_id');
    }
}
