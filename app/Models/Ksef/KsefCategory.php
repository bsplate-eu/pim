<?php

namespace App\Models\Ksef;

use Illuminate\Database\Eloquent\Model;

/**
 * Kategoria FV KSeF (per firma) — edytowana w zakładce „Ustawienia".
 */
class KsefCategory extends Model
{
    protected $table = 'ksef_categories';

    protected $fillable = [
        'company',
        'name',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];
}
