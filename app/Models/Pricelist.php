<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pricelist extends Model {


    protected $table = 'pricelists';
    protected $fillable = ['slug', 'name', 'currency', 'sheet_id', 'price_formula', 'price_formula_mode', 'position'];

    /**
     * Nowy cennik (tworzony recznie, przez klon albo import) laduje na koncu listy.
     */
    protected static function booted(): void
    {
        static::creating(function (self $pricelist) {
            if (empty($pricelist->position)) {
                $pricelist->position = (int) static::max('position') + 1;
            }
        });
    }
}
