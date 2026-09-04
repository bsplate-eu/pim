<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rezerwacja pozycji magazynowej: ktos odklada X sztuk kodu dla siebie.
 *
 * Rezerwacja NIE zmienia stanu — stan mowi, ile fizycznie lezy na polce,
 * a rezerwacja, ile z tego jest juz obiecane. Zlanie tych dwoch rzeczy w jedna
 * liczbe konczy sie tym, ze nikt nie wie, czy towaru brakuje, czy tylko ktos
 * go trzyma.
 */
class WarehouseReservation extends Model
{
    protected $table = 'warehouse_reservations';

    protected $fillable = [
        'source', 'source_code', 'product_code',
        'quantity', 'user_id', 'user_name', 'note',
        'released_at', 'released_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'released_at' => 'datetime',
    ];

    /** Tylko zywe rezerwacje — zwolnione zostaja w bazie dla historii. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    /** „Maciej Zajac 1” — tak, jak ma sie pokazac pod iloscia w aplikacji. */
    public function label(): string
    {
        return trim(($this->user_name ?? 'nieznany').' '.$this->quantity);
    }
}
