<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranslationPhraseRendition extends Model
{
    protected $table = 'translation_phrase_renditions';

    protected $fillable = [
        'translation_phrase_id',
        'channel',
        'value',
        'source',
        'variants_count',
    ];

    protected $casts = [
        'variants_count' => 'integer',
    ];

    public function phrase(): BelongsTo
    {
        return $this->belongsTo(TranslationPhrase::class, 'translation_phrase_id');
    }
}
