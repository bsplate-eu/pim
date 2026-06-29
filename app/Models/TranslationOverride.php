<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TranslationOverride extends Model
{
    public const SOURCE_MANUAL       = 'manual';
    public const SOURCE_SHEET_IMPORT = 'sheet_import';
    public const SOURCE_AI           = 'ai';
    public const SOURCE_AUTO_MATRIX  = 'auto_matrix';

    /** Źródła które blokują nadpisywanie przez SumpguardSource (i inne źródła danych). */
    public const LOCKING_SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_SHEET_IMPORT,
        self::SOURCE_AUTO_MATRIX, // matryca też chroni — re-translate musi przejść świadomie przez Composer.
    ];

    protected $table = 'translation_overrides';

    protected $fillable = [
        'translatable_type',
        'translatable_id',
        'field',
        'locale',
        'source',
        'user_id',
        'locked_at',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
    ];

    /** Flaga procesowa — observerzy ją sprawdzają, żeby NIE zapisać override gdy zmiana idzie z importu/auto. */
    public static bool $suppressObserver = false;

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Czy dany slot (model+field+locale) jest chroniony przed nadpisaniem przez automaty?
     */
    public static function isLocked(Model $model, string $field, string $locale): bool
    {
        return static::query()
            ->where('translatable_type', static::morphType($model))
            ->where('translatable_id', $model->getKey())
            ->where('field', $field)
            ->where('locale', $locale)
            ->whereIn('source', self::LOCKING_SOURCES)
            ->exists();
    }

    /**
     * Lista lokali kt\xc3\xb3re s\xc4\x85 zablokowane dla danego (model, field).
     * @return string[]
     */
    public static function lockedLocales(Model $model, string $field): array
    {
        return static::query()
            ->where('translatable_type', static::morphType($model))
            ->where('translatable_id', $model->getKey())
            ->where('field', $field)
            ->whereIn('source', self::LOCKING_SOURCES)
            ->pluck('locale')
            ->all();
    }

    public static function mark(Model $model, string $field, string $locale, string $source, ?int $userId = null): self
    {
        return static::updateOrCreate(
            [
                'translatable_type' => static::morphType($model),
                'translatable_id'   => $model->getKey(),
                'field'             => $field,
                'locale'            => $locale,
            ],
            [
                'source'    => $source,
                'user_id'   => $userId,
                'locked_at' => now(),
            ]
        );
    }

    private static function morphType(Model $model): string
    {
        return $model->getMorphClass();
    }
}
