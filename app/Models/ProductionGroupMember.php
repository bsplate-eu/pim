<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wariant kodu w grupie. `included = false` znaczy, ze uzytkownik go odpial —
 * zostaje wtedy osobnym wierszem w tabeli produkcji.
 */
class ProductionGroupMember extends Model
{
    protected $table = 'production_group_members';

    protected $fillable = ['group_id', 'product_code', 'included'];

    protected $casts = ['included' => 'boolean'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductionGroup::class, 'group_id');
    }
}
