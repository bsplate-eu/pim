<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jedno przypisanie: kod zrodlowy (Subiekt GT albo arkusz) -> kod w PIM.
 */
class WarehouseCodeMap extends Model
{
    protected $table = 'warehouse_code_map';

    /** Zrodla, ktore wolno mapowac. Klucz techniczny => etykieta na ekranie. */
    public const SOURCES = [
        'gt' => 'Subiekt GT',
        'sheet' => 'Tabela',
    ];

    protected $fillable = [
        'product_code',
        'source',
        'source_code',
        'manual',
    ];

    protected $casts = [
        'manual' => 'boolean',
    ];
}
