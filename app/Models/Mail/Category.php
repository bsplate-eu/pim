<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $table = 'mail_categories';

    protected $guarded = ['id'];

    protected $casts = [
        'is_system' => 'boolean',
        'sort'      => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'category_id');
    }
}
