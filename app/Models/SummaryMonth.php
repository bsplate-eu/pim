<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SummaryMonth extends Model
{
    use HasFactory;

    protected $fillable = ['year', 'month', 'label', 'notes'];

    protected $casts = [
        'year'  => 'integer',
        'month' => 'integer',
    ];

    public const MONTH_NAMES = [
        1 => 'Styczeń', 2 => 'Luty', 3 => 'Marzec', 4 => 'Kwiecień',
        5 => 'Maj', 6 => 'Czerwiec', 7 => 'Lipiec', 8 => 'Sierpień',
        9 => 'Wrzesień', 10 => 'Październik', 11 => 'Listopad', 12 => 'Grudzień',
    ];

    public static function buildLabel(int $year, int $month): string
    {
        return (self::MONTH_NAMES[$month] ?? (string) $month) . ' ' . $year;
    }
}
