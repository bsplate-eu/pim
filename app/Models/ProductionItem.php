<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wiersz danych produkcyjnych przypiety do kodu (`products.product_code`).
 * Powstaje leniwie — dopiero gdy ktos cos na kodzie oznaczy.
 */
class ProductionItem extends Model
{
    protected $table = 'production_items';

    protected $fillable = [
        'product_code',
        'has_project',
        'team_steel',
        'etap_1',
        'etap_2',
        'etap_3',
        'gotowe',
        'bez_wspornikow',
        'projekty_gotowe',
        'sales_12m',
    ];

    protected $casts = [
        'has_project' => 'boolean',
        'team_steel' => 'boolean',
        'etap_1' => 'boolean',
        'etap_2' => 'boolean',
        'etap_3' => 'boolean',
        'gotowe' => 'boolean',
        'bez_wspornikow' => 'boolean',
        'projekty_gotowe' => 'boolean',
        'sales_12m' => 'integer',
    ];
}
