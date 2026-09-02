<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Etap produkcyjny — slownik zarzadzany z Ustawien modulu.
 *
 * `sales_from` / `sales_to` to przedzial sprzedazy 12M, po ktorym etap jest
 * przypisywany automatycznie. Gorna granica NULL = bez limitu. Oba NULL =
 * etap istnieje, ale automat go nie uzywa.
 */
class ProductionStage extends Model
{
    protected $table = 'production_stages';

    protected $fillable = [
        'name',
        'color',
        'sales_from',
        'sales_to',
        'position',
    ];

    protected $casts = [
        'sales_from' => 'integer',
        'sales_to' => 'integer',
        'position' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ProductionItem::class, 'stage_id');
    }

    /** Czy etap bierze udzial w automatycznym przypisywaniu. */
    public function hasRange(): bool
    {
        return $this->sales_from !== null || $this->sales_to !== null;
    }

    /** Czy podana sprzedaz 12M miesci sie w przedziale etapu. */
    public function matches(int $sales): bool
    {
        if (! $this->hasRange()) {
            return false;
        }

        if ($this->sales_from !== null && $sales < $this->sales_from) {
            return false;
        }

        return $this->sales_to === null || $sales <= $this->sales_to;
    }
}
