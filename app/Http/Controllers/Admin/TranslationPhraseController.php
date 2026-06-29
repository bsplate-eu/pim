<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\TranslationPhrase;
use App\Models\TranslationPhraseRendition;
use App\Services\ProductTranslationComposer;
use App\Queries\Filters\FuzzyFilter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TranslationPhraseController extends Controller
{
    public const CHANNELS = [
        'pl' => 'PL',
        'de' => 'DE',
        'cs' => 'CS',
        'sk' => 'SK',
        'fr' => 'FR',
        'es' => 'ES',
        'allegro_klapypodsilnik' => 'Allegro · klapypodsilnik',
        'allegro_czescipareto'   => 'Allegro · czescipareto',
        'allegro_dolneoslony'    => 'Allegro · dolneoslony',
        'allegro_ksteileshop'    => 'Allegro · ksteileshop',
        'allegro_oslonypareto'   => 'Allegro · oslonypareto',
    ];

    public function index(Request $request): Response
    {
        $query = QueryBuilder::for(TranslationPhrase::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter('id', 'slug', 'phrase_pl')),
            ])
            ->withCount('renditions')
            ->defaultSort('-product_count')
            ->allowedSorts('id', 'slug', 'phrase_pl', 'product_count', 'renditions_count');

        $phrases = $query->paginate($request->get('per_page', 50))->withQueryString();

        return Inertia::render('TranslationPhrase/Index', [
            'phrases'      => $phrases,
            'channelLabels' => self::CHANNELS,
        ]);
    }

    public function edit(Request $request, TranslationPhrase $translationPhrase): Response
    {
        $translationPhrase->load('renditions');
        $renditionsByChannel = $translationPhrase->renditions->keyBy('channel');

        $rows = [];
        foreach (self::CHANNELS as $key => $label) {
            $r = $renditionsByChannel->get($key);
            $rows[] = [
                'channel'        => $key,
                'label'          => $label,
                'value'          => $r?->value ?? '',
                'source'         => $r?->source ?? null,
                'variants_count' => $r?->variants_count ?? 0,
                'has_value'      => $r !== null,
            ];
        }

        return Inertia::render('TranslationPhrase/Edit', [
            'phrase'      => $translationPhrase,
            'renditions'  => $rows,
        ]);
    }

    public function update(Request $request, TranslationPhrase $translationPhrase)
    {
        $validated = $request->validate([
            'phrase_pl'                  => 'required|string|max:500',
            'renditions'                 => 'required|array',
            'renditions.*.channel'       => 'required|string',
            'renditions.*.value'         => 'nullable|string|max:1000',
        ]);

        $translationPhrase->update(['phrase_pl' => $validated['phrase_pl']]);

        foreach ($validated['renditions'] as $row) {
            $channel = $row['channel'];
            $value = trim($row['value'] ?? '');
            if ($value === '') {
                TranslationPhraseRendition::where('translation_phrase_id', $translationPhrase->id)
                    ->where('channel', $channel)
                    ->delete();
                continue;
            }
            TranslationPhraseRendition::updateOrCreate(
                ['translation_phrase_id' => $translationPhrase->id, 'channel' => $channel],
                ['value' => $value, 'source' => 'manual', 'variants_count' => 1]
            );
        }

        return redirect()->route('crafter.translation-phrases.edit', $translationPhrase)
            ->with(['message' => 'Fraza zaktualizowana']);
    }

    public function reapply(Request $request, TranslationPhrase $translationPhrase, ProductTranslationComposer $composer)
    {
        // Znajdź produkty których PL-prefix matchuje tę frazę → uruchom composer dla każdego.
        $count = 0;
        $stats = ['matched' => 0, 'applied_locales' => 0, 'applied_integrations' => 0, 'skipped_locked' => 0];

        Product::with('attributeValues.attribute')
            ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, '$.pl'))) LIKE ?", [
                '%' . mb_strtolower($translationPhrase->phrase_pl) . '%',
            ])
            ->chunk(50, function ($products) use ($composer, $translationPhrase, &$count, &$stats) {
                foreach ($products as $product) {
                    $proposal = $composer->compose($product);
                    if (!$proposal['matched'] || $proposal['phrase_id'] !== $translationPhrase->id) continue;
                    $s = $composer->apply($product);
                    if ($s['matched']) $stats['matched']++;
                    $stats['applied_locales']      += $s['applied_locales'];
                    $stats['applied_integrations'] += $s['applied_integrations'];
                    $stats['skipped_locked']       += $s['skipped_locked'];
                    $count++;
                }
            });

        return redirect()->back()->with([
            'message' => sprintf(
                'Reaplikowano dla %d produktów (locale: %d, Allegro: %d, pominięto chronionych: %d)',
                $stats['matched'],
                $stats['applied_locales'],
                $stats['applied_integrations'],
                $stats['skipped_locked']
            ),
        ]);
    }
}
