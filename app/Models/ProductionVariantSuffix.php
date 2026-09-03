<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sufiks materialowy — jedyny rodzaj koncowki, ktora trzon ucina.
 *
 * ALU/GAL/INOX to ten sam wyrob w innym materiale, wiec laczy sie z baza.
 * Koncowki typu „W" czy „A" oznaczaja INNA oslone i musza zostac w kodzie —
 * inaczej 30.144W wpadloby pod 30.144, a 30.145A pod 30.145.
 */
class ProductionVariantSuffix extends Model
{
    protected $table = 'production_variant_suffixes';

    protected $fillable = ['suffix'];

    public function setSuffixAttribute(string $value): void
    {
        // Porownania robimy na wersji bez spacji i myslnikow, wielkimi literami.
        $this->attributes['suffix'] = strtoupper(trim(str_replace([' ', '-'], '', $value)));
    }
}
