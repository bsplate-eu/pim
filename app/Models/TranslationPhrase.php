<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TranslationPhrase extends Model
{
    protected $table = 'translation_phrases';

    protected $fillable = ['slug', 'phrase_pl', 'product_count'];

    protected $casts = [
        'product_count' => 'integer',
    ];

    public function renditions(): HasMany
    {
        return $this->hasMany(TranslationPhraseRendition::class);
    }

    public function rendition(string $channel): ?TranslationPhraseRendition
    {
        return $this->renditions->firstWhere('channel', $channel);
    }
}
