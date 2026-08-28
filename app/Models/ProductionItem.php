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
    ];

    protected $casts = [
        'has_project' => 'boolean',
    ];
}
