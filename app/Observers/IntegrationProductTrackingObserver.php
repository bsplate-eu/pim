<?php

namespace App\Observers;

use App\Models\IntegrationProduct;
use App\Models\TranslationOverride;

/**
 * Flaguje zmiany w `integration_products.overrides.*` (per integracja, np. per konto Allegro).
 * To NIE jest multi-locale JSON — to per-integracja string override (np. `overrides.name`).
 *
 * Locale w `translation_overrides` zapisujemy jako "int:{integration_id}" — żeby odróżnić
 * od standardowych slotów Spatie ('pl','de',...).
 */
class IntegrationProductTrackingObserver
{
    /** Pola w overrides które chronimy przed nadpisaniem. */
    private const TRACKED_FIELDS = ['name', 'ean', 'description'];

    public function saving(IntegrationProduct $ip): void
    {
        if (TranslationOverride::$suppressObserver) return;
        if (!$ip->isDirty('overrides')) return;

        $userId = auth()->check() ? auth()->id() : null;

        $original = $this->decode($ip->getOriginal('overrides'));
        $current  = $ip->overrides ?? [];

        foreach (self::TRACKED_FIELDS as $field) {
            $oldVal = $original[$field] ?? null;
            $newVal = $current[$field]  ?? null;
            if ($oldVal === $newVal) continue;

            TranslationOverride::mark(
                $ip,
                "overrides.{$field}",
                'int:' . $ip->integration_id,
                TranslationOverride::SOURCE_MANUAL,
                $userId
            );
        }
    }

    public function deleted(IntegrationProduct $ip): void
    {
        TranslationOverride::query()
            ->where('translatable_type', $ip->getMorphClass())
            ->where('translatable_id', $ip->getKey())
            ->delete();
    }

    private function decode($raw): array
    {
        if ($raw === null || $raw === '') return [];
        if (is_array($raw)) return $raw;
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
