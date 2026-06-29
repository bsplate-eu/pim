<?php

namespace App\Services;

use App\Models\TranslationPhrase;
use App\Models\TranslationPhraseRendition;
use Illuminate\Support\Str;

/**
 * Automatyczny generator renditcji (tłumaczeń frazy per kanał) dla fraz POCHODNYCH.
 *
 * Zasada: nowa fraza-wariant powstaje z istniejącej frazy BAZOWEJ przez deterministyczną transformację:
 *   - MATERIAŁ:    "Aluminiowa osłona X"  ← "Stalowa osłona X"  (podmiana słowa materiału per kanał)
 *   - MODYFIKATOR: "... z Webasto"        ← "..."              (doklejenie przetłumaczonego sufiksu)
 *   - KOMBINACJA:  "... i skrzyni biegów" ← "..."              (doklejenie)
 *
 * Dzięki temu wystarczy że w matrycy istnieje BAZA (np. "Stalowa osłona silnika" z arkusza) — wszystkie
 * jej warianty (aluminiowa, z Webasto, System Start-Stop, kombinacje) system generuje SAM, bez ręcznej pracy.
 *
 * Nowy ELEMENT (nieznany rzeczownik, np. pierwsza w historii "osłona miednicy") nie ma bazy → deriveFor
 * zwraca 0 i produkt trafia do review (jedyny przypadek wymagający człowieka — bo to nowe słowo do przetłumaczenia).
 *
 * Wpięty w: TranslationsReclassify (po przebudowie matrycy) i SumpguardSource (nowy produkt).
 */
class PhraseRenditionDeriver
{
    /** Podmiana materiału per kanał (Stal → Aluminium). Pierwszy pasujący wzorzec wygrywa. */
    private const MATERIAL_MAP = [
        'pl'                     => ['Stalowa' => 'Aluminiowa', 'Stal' => 'Aluminium'],
        'de'                     => ['Stahl' => 'Aluminium'],
        'cs'                     => ['Ocelový' => 'Hliníkový', 'Ocelová' => 'Hliníková', 'Ocelové' => 'Hliníkové'],
        'sk'                     => ['Oceľový' => 'Hliníkový', 'Oceľová' => 'Hliníková', 'Oceľové' => 'Hliníkové'],
        'fr'                     => ['Acier' => 'Aluminium'],
        'es'                     => ['metálico' => 'de aluminio', 'metalico' => 'de aluminio', 'Acero' => 'Aluminio'],
        'allegro_klapypodsilnik' => ['Stalowa' => 'Aluminiowa', 'Stal' => 'Aluminium'],
        'allegro_czescipareto'   => ['Metalowa' => 'Aluminiowa', 'Stalowa' => 'Aluminiowa'],
        'allegro_ksteileshop'    => ['Stalowa' => 'Aluminiowa', 'Stal' => 'Aluminium'],
        'allegro_dolneoslony'    => [], // "Klapa pod silnik" — brak materiału w nazwie
        'allegro_oslonypareto'   => [], // "Dolna osłona silnika" — brak materiału w nazwie
    ];

    /** Sufiks PL (na końcu frazy) → tłumaczenie per kanał (doklejane do renditcji bazy). */
    private const SUFFIX_TRANSLATIONS = [
        'i skrzyni biegów' => [
            'pl' => 'i skrzyni biegów', 'de' => 'und Getriebe', 'cs' => 'a převodovky',
            'sk' => 'a prevodovky', 'fr' => 'et boîte de vitesses', 'es' => 'y caja de cambios',
        ],
        'System Start-Stop' => [
            'pl' => 'System Start-Stop', 'de' => 'Start-Stop-System', 'cs' => 'systém Start-Stop',
            'sk' => 'systém Start-Stop', 'fr' => 'système Start-Stop', 'es' => 'sistema Start-Stop',
        ],
        'z Webasto' => [
            'pl' => 'z Webasto', 'de' => 'mit Webasto', 'cs' => 's Webasto',
            'sk' => 's Webasto', 'fr' => 'avec Webasto', 'es' => 'con Webasto',
        ],
        'galwanizowana' => [
            'pl' => 'galwanizowana', 'de' => 'verzinkt', 'cs' => 'galvanizovaný',
            'sk' => 'galvanizovaný', 'fr' => 'galvanisé', 'es' => 'galvanizado',
        ],
    ];

    private const ALLEGRO_CHANNELS = [
        'allegro_klapypodsilnik', 'allegro_czescipareto', 'allegro_dolneoslony',
        'allegro_ksteileshop', 'allegro_oslonypareto',
    ];

    /**
     * Próbuje wygenerować renditcje dla frazy pochodnej z jej bazy.
     *
     * @param bool $overwrite Nadpisz istniejące niepuste renditcje (domyślnie tylko uzupełnia braki).
     * @return int Liczba zapisanych renditcji (0 = nie udało się — brak bazy lub fraza bazowa/nieznany element).
     */
    public function deriveFor(TranslationPhrase $phrase, bool $overwrite = false): int
    {
        // 1. Sufiks (modyfikator lub kombinacja) — ma priorytet, bo np. "Aluminiowa ... z Webasto"
        //    najpierw zdejmuje " z Webasto" (baza "Aluminiowa ...", która sama derive z materiału).
        foreach (self::SUFFIX_TRANSLATIONS as $suffixPl => $map) {
            if (Str::endsWith($phrase->phrase_pl, ' ' . $suffixPl)) {
                $basePl = Str::beforeLast($phrase->phrase_pl, ' ' . $suffixPl);
                return $this->buildFromBase($phrase, $basePl, fn ($val, $channel) =>
                    $val . ' ' . ($this->channelSuffix($map, $channel)), $overwrite);
            }
        }

        // 2. Materiał: Aluminiowa ← Stalowa
        if (Str::startsWith($phrase->phrase_pl, 'Aluminiowa')) {
            $basePl = Str::replaceFirst('Aluminiowa', 'Stalowa', $phrase->phrase_pl);
            return $this->buildFromBase($phrase, $basePl, fn ($val, $channel) =>
                $this->swapMaterial($val, $channel), $overwrite);
        }

        return 0; // fraza bazowa lub nieznany element — nic do wyprowadzenia
    }

    /**
     * Buduje renditcje frazy `$target` z bazy o nazwie `$basePl`, transformując każdą renditcję bazy przez `$transform`.
     */
    private function buildFromBase(TranslationPhrase $target, string $basePl, callable $transform, bool $overwrite): int
    {
        $base = TranslationPhrase::with('renditions')
            ->where('slug', Str::slug($basePl, '_'))
            ->first();
        if (!$base) {
            return 0;
        }
        $baseRenditions = $base->renditions->filter(fn ($r) => (string) $r->value !== '');
        if ($baseRenditions->isEmpty()) {
            return 0; // baza jeszcze pusta — pętla auto-derive wróci tu po wygenerowaniu bazy
        }

        $existing = $target->renditions()->pluck('value', 'channel');
        $written = 0;
        foreach ($baseRenditions as $rendition) {
            $channel = $rendition->channel;
            if (!$overwrite && (string) ($existing[$channel] ?? '') !== '') {
                continue; // nie nadpisuj istniejących (chyba że overwrite)
            }
            $value = $transform((string) $rendition->value, $channel);
            TranslationPhraseRendition::updateOrCreate(
                ['translation_phrase_id' => $target->id, 'channel' => $channel],
                ['value' => $value, 'source' => 'derived']
            );
            $written++;
        }
        return $written;
    }

    private function channelSuffix(array $map, string $channel): string
    {
        if (in_array($channel, self::ALLEGRO_CHANNELS, true)) {
            return $map['pl']; // Allegro = rynek polski
        }
        return $map[$channel] ?? $map['pl'];
    }

    private function swapMaterial(string $text, string $channel): string
    {
        foreach (self::MATERIAL_MAP[$channel] ?? [] as $from => $to) {
            $pattern = '/' . preg_quote($from, '/') . '/iu';
            if (preg_match($pattern, $text)) {
                return preg_replace($pattern, $to, $text, 1);
            }
        }
        return $text; // brak słowa materiału w tym kanale — bez zmian
    }

    /**
     * Auto-derive dla CAŁEJ matrycy: powtarza przebiegi aż żadna nowa renditcja nie powstanie
     * (rozwiązuje łańcuchy zależności: Stalowa silnik → Aluminiowa silnik → Aluminiowa silnik z Webasto).
     *
     * @return array{phrases_filled: int, renditions_written: int, passes: int}
     */
    public function deriveAll(bool $overwrite = false): array
    {
        $phrasesFilled = 0;
        $renditionsWritten = 0;
        $passes = 0;

        do {
            $passes++;
            $changedThisPass = 0;

            // Wszystkie frazy — deriveFor sam pomija kanały już wypełnione (bez overwrite),
            // więc frazy częściowe (np. 2/11 z arkusza) też dostają uzupełnienie braków.
            $candidates = TranslationPhrase::query()->get();

            foreach ($candidates as $phrase) {
                $n = $this->deriveFor($phrase, $overwrite);
                if ($n > 0) {
                    $phrasesFilled++;
                    $renditionsWritten += $n;
                    $changedThisPass++;
                }
            }
        } while ($changedThisPass > 0 && $passes < 10);

        return ['phrases_filled' => $phrasesFilled, 'renditions_written' => $renditionsWritten, 'passes' => $passes];
    }
}
