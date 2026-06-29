<?php

namespace App\Services;

use App\Models\IntegrationProduct;
use App\Models\Product;
use App\Models\TranslationOverride;
use App\Models\TranslationPhrase;
use Illuminate\Support\Facades\DB;

/**
 * Sklejacz nazw produktów dla wszystkich kanałów (lokali + kont Allegro) z matrycy fraz.
 *
 * Wejście: Product (z atrybutami `make`, `model` i z nazwą PL).
 * Wyjście: dla każdego kanału (pl/de/cs/sk/fr/es + 5 Allegro) — pełna nazwa lub null jeśli brak frazy w matrycy.
 *
 * Algorytm:
 *   1. ProductPhraseClassifier rozpoznaje frazę kanoniczną z PL nazwy (materiał × element × wykończenie).
 *      NIE odcina marki/modelu — rozpoznaje typ z samych słów technicznych (odporne na "A4 B9", "Vauxhall Vivaro").
 *   2. Zbierz make+model z atrybutów PIM (language-neutral) — do doklejenia konkretnego pojazdu.
 *   3. Znajdź TranslationPhrase po slug frazy kanonicznej. Jeśli brak — produkt do review.
 *   4. Dla każdej rendition: skleić jako `{value} {make} {model}`.
 *
 * Apply zapisuje wynik do:
 *   - products.name->{locale} dla locale OBCYCH (de/cs/sk/fr/es) — z flagą `auto_matrix`.
 *     PL NIE jest nadpisywane — to język źródłowy (zachowuje pełny wariant pojazdu z feedu Sumpguard).
 *   - integration_products.overrides.name per konto Allegro (integracje 13/14/16/17/18 + alias 12)
 *
 * Nie nadpisuje slotów oznaczonych jako `manual`/`sheet_import`/`auto_matrix` (czyli idempotentny re-run nie kasuje
 * niczego — żeby przebudować, najpierw usuń wpis w `translation_overrides`).
 */
class ProductTranslationComposer
{
    public function __construct(
        private ProductPhraseClassifier $classifier,
        private PhraseRenditionDeriver $deriver,
    ) {
    }

    /** Kanały matrycy odpowiadające bezpośrednio locale w `products.name`. */
    public const LOCALE_CHANNELS = ['pl', 'de', 'cs', 'sk', 'fr', 'es'];

    /** Locale które composer ZAPISUJE. PL pominięte — to język źródłowy (nie nadpisujemy feedu). */
    public const WRITABLE_LOCALE_CHANNELS = ['de', 'cs', 'sk', 'fr', 'es'];

    /** Kanał matrycy → integration_id (per konto Allegro). */
    public const ALLEGRO_INTEGRATION_MAP = [
        'allegro_klapypodsilnik' => 13,
        'allegro_czescipareto'   => 14,
        'allegro_dolneoslony'    => 16,
        'allegro_ksteileshop'    => 17,
        'allegro_oslonypareto'   => 18,
    ];

    /** Integracja → kanał z którego kopiujemy (np. ID 12 = kopia allegro_oslonypareto). */
    public const ALLEGRO_INTEGRATION_ALIAS = [
        12 => 'allegro_oslonypareto',
    ];

    /**
     * Generuje propozycje nazw per kanał bez zapisywania.
     *
     * @return array{
     *   matched: bool,
     *   pl_prefix: ?string,
     *   make: ?string,
     *   model: ?string,
     *   phrase_id: ?int,
     *   channels: array<string, ?string>,
     * }
     */
    public function compose(Product $product): array
    {
        $product->loadMissing('attributeValues.attribute');

        [$make, $model] = $this->getMakeModel($product);
        $plName = $product->getTranslation('name', 'pl', false);

        $noMatch = fn (?string $prefix = null): array => [
            'matched'   => false,
            'pl_prefix' => $prefix,
            'make'      => $make,
            'model'     => $model,
            'phrase_id' => null,
            'channels'  => array_fill_keys($this->allChannelKeys(), null),
        ];

        if (!$plName) {
            return $noMatch();
        }

        // Klasyfikator rozpoznaje frazę kanoniczną z PL nazwy (bez odcinania marki/modelu).
        $classification = $this->classifier->classify($plName);
        if ($classification === null) {
            return $noMatch(); // element niejawny → review queue
        }

        $phrase = TranslationPhrase::with('renditions')
            ->where('slug', $classification['slug'])
            ->first();

        if (!$phrase) {
            return $noMatch($classification['phrase_pl']); // fraza nie ma jeszcze wpisu w matrycy
        }

        $channels = [];
        $renditionsByChannel = $phrase->renditions->keyBy('channel');
        foreach ($this->allChannelKeys() as $channel) {
            if ($channel === 'pl') {
                // PL = wyprostowany: czysty typ z klasyfikatora + ogon oryginału od marki
                // (naprawia śmieciowy feed "Stalowa Osłona pod silnik ... Aluminium", zachowuje warianty jak 4x4/Diesel).
                $channels['pl'] = $this->straightenPl($plName, $make, $model, $classification['phrase_pl'], $classification['element']);
                continue;
            }
            $rendition = $renditionsByChannel->get($channel);
            $channels[$channel] = $rendition
                ? $this->joinPrefixWithSuffix($rendition->value, $make, $model)
                : null;
        }

        return [
            'matched'   => true,
            'pl_prefix' => $classification['phrase_pl'],
            'make'      => $make,
            'model'     => $model,
            'phrase_id' => $phrase->id,
            'channels'  => $channels,
        ];
    }

    /**
     * Aplikuje propozycje do bazy. Nie nadpisuje slotów już zablokowanych (manual/sheet_import/auto_matrix).
     *
     * @return array{matched: bool, applied_locales: int, applied_integrations: int, skipped_locked: int}
     */
    public function apply(Product $product): array
    {
        // Samowystarczalność: upewnij się, że fraza istnieje w matrycy i ma tłumaczenia.
        // Nowy wariant (np. "Aluminiowa osłona dyferencjału z Webasto") powstanie i wygeneruje się SAM.
        $this->ensurePhrase($product);

        $proposal = $this->compose($product);
        $stats = ['matched' => $proposal['matched'], 'applied_locales' => 0, 'applied_integrations' => 0, 'skipped_locked' => 0];
        if (!$proposal['matched']) return $stats;

        DB::transaction(function () use ($product, $proposal, &$stats) {
            // === Locale: products.name ===
            $lockedNameLocales = TranslationOverride::lockedLocales($product, 'name');
            $changed = false;

            // PL prostujemy, ale chronimy WYŁĄCZNIE ręczne źródła (manual/sheet_import).
            // auto_matrix nie blokuje — composer może re-prostować własny zapis (idempotentnie).
            $plLockSource = TranslationOverride::query()
                ->where('translatable_type', $product->getMorphClass())
                ->where('translatable_id', $product->getKey())
                ->where('field', 'name')->where('locale', 'pl')
                ->value('source');
            $plProtected = in_array($plLockSource, [TranslationOverride::SOURCE_MANUAL, TranslationOverride::SOURCE_SHEET_IMPORT], true);

            TranslationOverride::$suppressObserver = true;
            try {
                $plValue = $proposal['channels']['pl'] ?? null;
                $plWritten = false;
                if ($plValue && !$plProtected && $product->getTranslation('name', 'pl', false) !== $plValue) {
                    $product->setTranslation('name', 'pl', $plValue);
                    $changed = true;
                    $plWritten = true;
                }

                foreach (self::WRITABLE_LOCALE_CHANNELS as $locale) {
                    $value = $proposal['channels'][$locale] ?? null;
                    if (!$value) continue;
                    if (in_array($locale, $lockedNameLocales, true)) {
                        $stats['skipped_locked']++;
                        continue;
                    }
                    $product->setTranslation('name', $locale, $value);
                    $changed = true;
                }
                if ($changed) {
                    $product->save();
                    if ($plWritten) {
                        TranslationOverride::mark($product, 'name', 'pl', TranslationOverride::SOURCE_AUTO_MATRIX);
                    }
                    foreach (self::WRITABLE_LOCALE_CHANNELS as $locale) {
                        $value = $proposal['channels'][$locale] ?? null;
                        if (!$value) continue;
                        if (in_array($locale, $lockedNameLocales, true)) continue;
                        TranslationOverride::mark($product, 'name', $locale, TranslationOverride::SOURCE_AUTO_MATRIX);
                        $stats['applied_locales']++;
                    }
                }

                // === Allegro: integration_products.overrides.name ===
                $allegroMap = self::ALLEGRO_INTEGRATION_MAP + array_map(
                    fn ($srcChannel) => $proposal['channels'][$srcChannel] ?? null,
                    self::ALLEGRO_INTEGRATION_ALIAS
                );

                foreach (self::ALLEGRO_INTEGRATION_MAP as $channel => $integrationId) {
                    $value = $proposal['channels'][$channel] ?? null;
                    if (!$value) continue;
                    $this->applyIntegrationOverride($product, $integrationId, $value, $stats);
                }
                foreach (self::ALLEGRO_INTEGRATION_ALIAS as $integrationId => $sourceChannel) {
                    $value = $proposal['channels'][$sourceChannel] ?? null;
                    if (!$value) continue;
                    $this->applyIntegrationOverride($product, $integrationId, $value, $stats);
                }
            } finally {
                TranslationOverride::$suppressObserver = false;
            }
        });

        return $stats;
    }

    private function applyIntegrationOverride(Product $product, int $integrationId, string $value, array &$stats): void
    {
        $ip = IntegrationProduct::firstOrNew([
            'integration_id' => $integrationId,
            'product_id'     => $product->id,
        ]);
        if (!$ip->exists) {
            $integration = \App\Models\Integration::with('integrationSources')->find($integrationId);
            $sourceId = $integration?->integrationSources->first()?->id;
            if (!$sourceId) return; // integracja bez sources → no-op
            $ip->integration_source_id = $sourceId;
            $ip->state = IntegrationProduct::STATE_PENDING;
        }

        // Sprawdź lock dla 'overrides.name' z locale 'int:{id}'
        $isLocked = TranslationOverride::isLocked($ip, 'overrides.name', 'int:' . $integrationId);
        if ($isLocked) {
            $stats['skipped_locked']++;
            return;
        }

        $overrides = $ip->overrides ?? [];
        if (($overrides['name'] ?? null) === $value) {
            return; // bez zmian
        }
        $overrides['name'] = $value;
        $ip->overrides = $overrides;
        $ip->save();

        TranslationOverride::mark($ip, 'overrides.name', 'int:' . $integrationId, TranslationOverride::SOURCE_AUTO_MATRIX);
        $stats['applied_integrations']++;
    }

    private function allChannelKeys(): array
    {
        return array_merge(self::LOCALE_CHANNELS, array_keys(self::ALLEGRO_INTEGRATION_MAP));
    }

    /**
     * Gwarantuje, że fraza dla produktu istnieje w matrycy i ma tłumaczenia.
     * Nowa fraza powstaje automatycznie; jeśli to wariant (aluminiowy/z modyfikatorem) — generuje się z bazy.
     */
    private function ensurePhrase(Product $product): void
    {
        $plName = $product->getTranslation('name', 'pl', false);
        if (!$plName) {
            return;
        }
        $classification = $this->classifier->classify($plName);
        if ($classification === null) {
            return; // nierozpoznany typ → produkt zostaje do review
        }

        $phrase = TranslationPhrase::firstOrCreate(
            ['slug' => $classification['slug']],
            ['phrase_pl' => $classification['phrase_pl'], 'product_count' => 0]
        );

        $hasRenditions = $phrase->renditions()
            ->where('value', '<>', '')->whereNotNull('value')->exists();
        if (!$hasRenditions) {
            $this->deriver->deriveFor($phrase);
        }
    }

    private function getMakeModel(Product $product): array
    {
        $make = $model = null;
        foreach ($product->attributeValues as $av) {
            $slug = $av->attribute?->slug;
            if ($slug === 'make' && !$make) $make = $av->getTranslation('name', 'pl');
            elseif ($slug === 'model' && !$model) $model = $av->getTranslation('name', 'pl');
        }
        return [$make, $model];
    }

    private function joinPrefixWithSuffix(string $prefix, ?string $make, ?string $model): string
    {
        $parts = [trim($prefix)];
        if ($make) $parts[] = trim($make);
        if ($model) $parts[] = trim($model);
        return implode(' ', array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * Kotwica elementu = rdzeń, po którym w oryginale zaczyna się ogon (marka/model/wariant).
     * Ogon liczony OD KOŃCA elementu (nie od marki) — żeby zachować warianty stojące przed marką
     * (np. "skrzyni biegów manualnej Audi": kotwica "bieg", ogon "manualnej Audi…").
     */
    private const ELEMENT_ANCHOR = [
        'silnika'                     => 'silnik',
        'skrzyni biegów'              => 'bieg',
        'silnika i skrzyni biegów'    => 'bieg',
        'skrzyni biegów i reduktora'  => 'reduktor',
        'dyferencjału'                => 'dyferencj',
        'zbiornika paliwa'            => 'paliw',
        'AdBlue'                      => 'adblue',
        'katalizatora'                => 'katalizator',
        'chłodnicy'                   => 'chłodnic',
        'reduktora'                   => 'reduktor',
        'DPF'                         => 'dpf',
        'EGR'                         => 'egr',
        'przedniego zderzaka'         => 'zderzak',
        'akumulatora'                 => 'akumulator',
        'filtra paliwa'               => 'paliw',
        'skrzynki transferowej'       => 'transfer',
        'czujnika tylnego wahacza'    => 'wahacz',
    ];

    /**
     * Prostuje śmieciowy PL z feedu do spójnego formatu, ZACHOWUJĄC wariant pojazdu.
     *
     * "Stalowa Osłona pod silnik Citroen Grand C4 SpaceTourer Aluminium"
     *   → "Aluminiowa osłona silnika Citroen Grand C4 SpaceTourer"
     *
     * Ogon brany OD KOŃCA elementu, więc warianty zarówno przed marką (manualnej) jak i po niej (4x4)
     * zostają. Strażnik: jeśli wynik zgubiłby jakiekolwiek istotne słowo (np. zła klasyfikacja),
     * zwracamy oryginał nietknięty.
     */
    public function straightenPl(string $plName, ?string $make, ?string $model, string $classifiedPhrase, string $element): string
    {
        $tail = null;
        $anchor = self::ELEMENT_ANCHOR[$element] ?? null;
        if ($anchor !== null) {
            $pos = mb_strripos($plName, $anchor);
            if ($pos !== false) {
                $rest = mb_substr($plName, $pos + mb_strlen($anchor));
                $rest = preg_replace('/^\S*/u', '', $rest); // dokończ bieżące słowo elementu
                $tail = ltrim((string) $rest);
            }
        }
        if ($tail === null) { // brak kotwicy → ogon od marki/modelu
            if ($make && ($p = mb_stripos($plName, $make)) !== false) {
                $tail = mb_substr($plName, $p);
            } else {
                $tail = trim(($make ?? '') . ' ' . ($model ?? ''));
            }
        }
        // słowo materiału "aluminium" gdziekolwiek w ogonie (to nie wariant pojazdu)
        $tail = preg_replace('/[\s\-–]*\baluminium\b/iu', '', (string) $tail);
        // modyfikatory/wykończenia są już w $classifiedPhrase — usuń je z ogona, by nie dublować
        // (np. ogon "z Webasto VW Sharan z Webasto" → "VW Sharan"). Czyni prostowanie idempotentnym.
        $tail = preg_replace('/[\s\-–]*\b(z\s+Webasto|System\s+Start[-\s]?Stop|Start[-\s]?Stop|Start[-\s]?Go|galwanizowan[aye])\b/iu', '', $tail);
        // wiszące wykończenie doklejone przez feed bez "z" (np. "VW Caddy - Webasto")
        $tail = preg_replace('/[\s\-–]+(Webasto|galwanizowan\w*)\s*$/iu', '', $tail);
        $tail = preg_replace('/\s{2,}/u', ' ', $tail);          // złóż podwójne spacje
        $tail = trim($tail, " \t-–,");

        // dedup marki: model w atrybucie czasem zawiera markę → ogon "Mercedes Mercedes V-Class"
        if ($make) {
            $tail = preg_replace('/^(' . preg_quote($make, '/') . ')\s+\1\b/iu', '$1', $tail);
        }

        $new = trim($classifiedPhrase . ($tail !== '' ? ' ' . $tail : ''));

        // STRAŻNIK: identyfikacja pojazdu musi przetrwać. Jeśli ANI marka ANI model nie zostały
        // w wyniku (zła kotwica / błędna klasyfikacja ucięła środek nazwy) — zwróć oryginał.
        // Luźno (przynajmniej jedno słowo), bo PL bywa skrótem marki (VW ≠ atrybut "Volkswagen").
        $newLower = mb_strtolower($new, 'UTF-8');
        $vehicleWords = [];
        foreach ([$make, $model] as $part) {
            if (!$part) continue;
            foreach (preg_split('/\s+/', mb_strtolower($part, 'UTF-8')) as $word) {
                $word = trim($word);
                if ($word !== '') $vehicleWords[] = $word;
            }
        }
        if ($vehicleWords !== []) {
            $anyPresent = false;
            foreach ($vehicleWords as $word) {
                if (mb_strpos($newLower, $word) !== false) { $anyPresent = true; break; }
            }
            if (!$anyPresent) return $plName;
        }
        return $new;
    }
}
