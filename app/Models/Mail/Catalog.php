<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Katalog (folder) użytkownika do ręcznego sortowania maili — drzewo (parent_id).
 * Mail może być przypisany do JEDNEGO katalogu (mail_messages.catalog_id).
 */
class Catalog extends Model
{
    use HasFactory;

    protected $table = 'mail_catalogs';

    protected $guarded = ['id'];

    protected $casts = [
        'parent_id' => 'integer',
        'sort'      => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'catalog_id');
    }
}
