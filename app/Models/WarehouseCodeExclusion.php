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

    /**
     * Co wolno odlozyc na bok. Obok zrodel (`gt`, `sheet`) jest tu `pim`:
     * wiersz LISTY M3R, czyli kod z naszego katalogu. To osobna przestrzen
     * nazw — `00.004` z Subiekta i `00.004` z PIM to dwie rozne decyzje.
     */
    public const SOURCES = [
        'gt' => 'Subiekt GT',
        'sheet' => 'Tabela',
        'pim' => 'Lista M3R',
    ];

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
