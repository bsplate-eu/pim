<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jeden wiersz arkusza inwentury — kod plus do szesciu par Miejsce/ilosc.
 */
class WarehouseSheetRow extends Model
{
    protected $table = 'warehouse_sheet_rows';

    /** Zakladka, ktora czytamy jako biezaca inwenture. */
    public const DEFAULT_SHEET = '2026 - inwentura';

    protected $guarded = [];

    protected $casts = [
        'row_no' => 'integer',
        'qty_1' => 'integer',
        'qty_2' => 'integer',
        'qty_3' => 'integer',
        'qty_4' => 'integer',
        'qty_5' => 'integer',
        'qty_6' => 'integer',
        'quantity_total' => 'integer',
    ];

    /**
     * Pary Miejsce/ilosc bez pustych — do wyswietlenia w jednej komorce
     * albo do policzenia, w ilu miejscach lezy kod.
     */
    public function places(): array
    {
        $out = [];

        foreach (range(1, 6) as $i) {
            if ($this->{"place_$i"} !== null || $this->{"qty_$i"} !== null) {
                $out[] = ['place' => $this->{"place_$i"}, 'qty' => $this->{"qty_$i"}];
            }
        }

        return $out;
    }
}
