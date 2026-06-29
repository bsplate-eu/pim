<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\TranslationPhrase;
use App\Models\TranslationPhraseRendition;
use App\Services\ProductPhraseClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Przebudowa matrycy fraz w oparciu o klasyfikator kanoniczny (materiał × element × wykończenie).
 *
 * Sprowadza setki śmieciowych fraz (z markami/modelami w slugu) do ~33 fraz kanonicznych.
 * - Frazy które OCALAJĄ slug (np. stalowa_oslona_silnika) zachowują swoje tłumaczenia (renditions).
 * - Nowe frazy powstają puste (do ręcznego uzupełnienia w UI matrycy).
 * - Stare frazy spoza nowego zbioru są usuwane dopiero z flagą --prune.
 *
 * Komenda dotyka WYŁĄCZNIE matrycy (translation_phrases + renditions).
 * NIE rusza products.name ani translation_overrides — wypełnienie nazw to osobny krok (auto-translate).
 */
class TranslationsReclassify extends Command
{
    protected $signature = 'translations:reclassify
        {--apply : Wykonaj przebudowę (bez tej flagi = tylko podgląd planu, zero zapisu)}
        {--prune : Usuń stare frazy spoza nowego zbioru kanonicznego (wymaga --apply)}
        {--show-unrecognized : Wypisz produkty nierozpoznane przez klasyfikator}';

    protected $description = 'Przebudowa matrycy fraz na podstawie klasyfikatora kanonicznego';

    public function handle(ProductPhraseClassifier $classifier, \App\Services\PhraseRenditionDeriver $deriver): int
    {
        $apply = (bool) $this->option('apply');
        $prune = (bool) $this->option('prune');

        // === 1. Klasyfikacja wszystkich produktów z niepustą nazwą PL ===
        $canonical    = []; // slug => ['phrase_pl' => string, 'count' => int, 'samples' => int[]]
        $unrecognized = []; // ['id' => int, 'pl' => string]
        $totalProducts = 0;

        $this->info('Klasyfikuję produkty...');
        Product::query()
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.pl')) IS NOT NULL")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.pl')) <> ''")
            ->chunkById(200, function ($chunk) use ($classifier, &$canonical, &$unrecognized, &$totalProducts) {
                foreach ($chunk as $product) {
                    $totalProducts++;
                    $pl  = $product->getTranslation('name', 'pl', false);
                    $res = $classifier->classify($pl);
                    if ($res === null) {
                        $unrecognized[] = ['id' => $product->id, 'pl' => $pl];
                        continue;
                    }
                    $slug = $res['slug'];
                    if (!isset($canonical[$slug])) {
                        $canonical[$slug] = ['phrase_pl' => $res['phrase_pl'], 'count' => 0, 'samples' => []];
                    }
                    $canonical[$slug]['count']++;
                    if (count($canonical[$slug]['samples']) < 3) {
                        $canonical[$slug]['samples'][] = $product->id;
                    }
                }
            });

        // posortuj malejąco po liczbie produktów
        uasort($canonical, fn ($a, $b) => $b['count'] <=> $a['count']);

        // === 2. Istniejąca matryca + pokrycie renditions ===
        $existing = TranslationPhrase::query()
            ->withCount(['renditions as filled_renditions' => function ($q) {
                $q->whereNotNull('value')->where('value', '<>', '');
            }])
            ->get()
            ->keyBy('slug');

        $canonicalSlugs = array_keys($canonical);
        $existingSlugs  = $existing->keys()->all();

        $keep   = array_values(array_intersect($canonicalSlugs, $existingSlugs)); // ocalają (slug istnieje)
        $new    = array_values(array_diff($canonicalSlugs, $existingSlugs));      // nowe (do utworzenia)
        $delete = array_values(array_diff($existingSlugs, $canonicalSlugs));      // śmieci (do usunięcia)

        // === 3. RAPORT ===
        $this->newLine();
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line(sprintf('  Produktów sklasyfikowanych: %d   |   nierozpoznanych: %d', $totalProducts - count($unrecognized), count($unrecognized)));
        $this->line(sprintf('  Fraz kanonicznych: %d   (ocala: %d, nowych: %d)', count($canonical), count($keep), count($new)));
        $this->line(sprintf('  Starych fraz do usunięcia (śmieci): %d', count($delete)));
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        $this->line('PROD   POKRYCIE   FRAZA KANONICZNA');
        foreach ($canonical as $slug => $info) {
            if (in_array($slug, $keep, true)) {
                $filled = (int) $existing[$slug]->filled_renditions;
                $cov    = $filled >= 10 ? "<info>{$filled}/11 ✓</info>" : ($filled >= 1 ? "<comment>{$filled}/11</comment>" : '<error>0/11</error>');
            } else {
                $cov = '<comment>NOWA</comment>';
            }
            $this->line(sprintf('%4d   %-18s %s', $info['count'], $cov, $info['phrase_pl']));
        }

        if ($unrecognized) {
            $this->newLine();
            $this->line(sprintf('<comment>NIEROZPOZNANE (%d)</comment> — element niejawny, do ręcznej decyzji:', count($unrecognized)));
            $show = $this->option('show-unrecognized') ? $unrecognized : array_slice($unrecognized, 0, 10);
            foreach ($show as $u) {
                $this->line(sprintf('  #%-6d %s', $u['id'], $u['pl']));
            }
            if (!$this->option('show-unrecognized') && count($unrecognized) > 10) {
                $this->line(sprintf('  ... i %d więcej (--show-unrecognized by zobaczyć wszystkie)', count($unrecognized) - 10));
            }
        }

        // === 4. APPLY ===
        if (!$apply) {
            $this->newLine();
            $this->info('To był podgląd (dry-run). Uruchom z --apply aby przebudować matrycę.');
            if (count($delete) > 0) {
                $this->line('Dodaj --prune aby usunąć ' . count($delete) . ' starych fraz-śmieci.');
            }
            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('APPLY: przebudowuję matrycę...');

        DB::transaction(function () use ($canonical, $prune, $delete, &$created, &$updated, &$pruned) {
            $created = $updated = $pruned = 0;

            foreach ($canonical as $slug => $info) {
                $phrase = TranslationPhrase::firstOrNew(['slug' => $slug]);
                $isNew  = !$phrase->exists;
                $phrase->phrase_pl     = $info['phrase_pl'];
                $phrase->product_count = $info['count'];
                $phrase->save();
                $isNew ? $created++ : $updated++;
            }

            if ($prune) {
                foreach ($delete as $slug) {
                    $old = TranslationPhrase::where('slug', $slug)->first();
                    if (!$old) continue;
                    TranslationPhraseRendition::where('translation_phrase_id', $old->id)->delete();
                    $old->delete();
                    $pruned++;
                }
            }
        });

        $this->info(sprintf('Gotowe. Utworzono: %d, zaktualizowano: %d, usunięto śmieci: %d', $created, $updated, $pruned));

        // Auto-derive: nowe frazy-warianty (aluminiowe, modyfikatory, kombinacje) generują tłumaczenia z baz.
        $this->line('Auto-derive renditcji wariantów...');
        $d = $deriver->deriveAll();
        $this->info(sprintf('  wygenerowano %d renditcji dla %d fraz (%d przebiegów)', $d['renditions_written'], $d['phrases_filled'], $d['passes']));
        if (!$prune && count($delete) > 0) {
            $this->line('Stare frazy-śmieci zostały (bez --prune). Uruchom z --prune by je usunąć.');
        }
        $this->newLine();
        $this->line('NASTĘPNE KROKI:');
        $this->line('  1. Uzupełnij tłumaczenia nowych fraz w UI: /admin/translation-phrases');
        $this->line('  2. Wypełnij nazwy produktów: translations:auto-translate (po odblokowaniu starych auto_matrix locków)');

        return self::SUCCESS;
    }
}
