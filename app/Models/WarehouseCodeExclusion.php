<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kod zrodlowy odlozony na bok — nie liczy sie nigdzie i nie zasmieca listy
 * „Do zmapowania". Wykluczenie nic nie kasuje: przywrocenie oddaje wiersz
 * w tym samym stanie, bo ilosci przyjezdzaja z kazda paczka od nowa.
 */
class WarehouseCodeExclusion extends Model
{
    protected $table = 'warehouse_code_exclusions';

    protected $fillable = [
        'source',
        'source_code',
    ];

    /** Klucz uzywany w mapach w pamieci: zrodlo + kod bez wzgledu na wielkosc liter. */
    public static function key(string $source, string $sourceCode): string
    {
        return $source.'|'.mb_strtoupper($sourceCode);
    }
}
