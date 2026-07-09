<?php

namespace App\Observers;

use App\Models\TranslationOverride;
use Illuminate\Database\Eloquent\Model;

/**
 * Generyczny observer dla modeli Spatie HasTranslations.
 * Flaguje zmienione (locale, field) jako 'manual' w `translation_overrides`,
 * żeby automaty (np. SumpguardSource) ich nie nadpisywały.
 *
 * Wyzwalany w `saving` żeby porównać DIRTY przed flushem.
 * Pomija gdy TranslationOverride::$suppressObserver = true
 * (ustawiane w komendach importu i w SumpguardSource — żeby automatyczne updaty NIE flagowały slotów jako manual).
 */
class TranslationTrackingObserver
{
    public function saving(Model $model): void
    {
        if (TranslationOverride::$suppressObserver) return;
        // Nowy rekord nie ma jeszcze id → mark() wstawiłby translatable_id=null (23000).
        // Świeżo utworzony rekord i tak nie ma nic do ochrony przed nadpisaniem.
        if (!$model->exists) return;
        if (!property_exists($model, 'translatable') || !is_array($model->translatable)) return;

        $userId = auth()->check() ? auth()->id() : null;

        foreach ($model->translatable as $field) {
            if (!$model->isDirty($field)) continue;

            $original = $this->decodeTranslations($model->getOriginal($field));
            $current  = $this->decodeTranslations($model->getAttributes()[$field] ?? null);

            $locales = array_unique(array_merge(array_keys($original), array_keys($current)));
            foreach ($locales as $locale) {
                $oldVal = $original[$locale] ?? null;
                $newVal = $current[$locale]  ?? null;
                if ($oldVal === $newVal) continue;

                // Slot dotknięty przez usera → flaga manual (chroni przed sumpguard).
                TranslationOverride::mark($model, $field, $locale, TranslationOverride::SOURCE_MANUAL, $userId);
            }
        }
    }

    public function deleted(Model $model): void
    {
        TranslationOverride::query()
            ->where('translatable_type', $model->getMorphClass())
            ->where('translatable_id', $model->getKey())
            ->delete();
    }

    /**
     * @return array<string, string|null>
     */
    private function decodeTranslations($raw): array
    {
        if ($raw === null || $raw === '') return [];
        if (is_array($raw)) return $raw;
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
