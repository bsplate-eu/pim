<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class AiTool extends Model
{

    use HasTranslations;

    protected $table = 'ai_tools';
    protected $fillable = ['name', 'description', 'provider', 'config', 'enabled', 'order'];

    public $translatable = ['name', 'description'];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
        'order' => 'integer',
    ];
}
