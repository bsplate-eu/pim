<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pricelist extends Model {


    protected $table = 'pricelists';
    protected $fillable = ['slug', 'name', 'currency', 'sheet_id', 'price_formula', 'price_formula_mode'];
}
