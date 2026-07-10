<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Source;
use App\Models\TranslationLog;
use App\Models\TranslationOverride;
use App\Services\ProductTranslationComposer;
use App\Settings\TranslationSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ustawienia modułu tłumaczeń (Tłumaczenia → Ustawienia).
 *
 * Zarządza: auto-tłumaczeniem nowych produktów, auto-approve oraz ręcznym
 * uruchomieniem "wyprostuj surowe nazwy" (composer prostuje PL "Osłona pod silnik" → "osłona silnika").
 */
class TranslationSettingsController extends Controller
{
    public function index(TranslationSettings $settings): Response
    {
        return Inertia::render('TranslationSettings/Index', [
            'settings' => [
                'auto_translate_on_sync'    => $settings->auto_translate_on_sync,
                'auto_approve_enabled'      => $settings->auto_approve_enabled,
                'auto_approve_min_coverage' => $settings->auto_approve_min_coverage,
            ],
            'stats' => $this->stats(),
        ]);
    }

    public function update(Request $request, TranslationSettings $settings)
    {
        $validated = $request->validate([
            'auto_translate_on_sync'    => ['required', 'boolean'],
            'auto_approve_enabled'      => ['required', 'boolean'],
            'auto_approve_min_coverage' => ['required', 'integer', 'min:1', 'max:6'],
        ]);

        $settings->auto_translate_on_sync    = $validated['auto_translate_on_sync'];
        $settings->auto_approve_enabled      = $validated['auto_approve_enabled'];
        $settings->auto_approve_min_coverage = $validated['auto_approve_min_coverage'];
        $settings->save();

        return redirect()->back()->with(['message' => 'Zapisano ustawienia tłumaczeń.']);
    }

    /**
     * Ręczne "wyprostuj surowe nazwy teraz" — composer prostuje PL dla produktów z surową nazwą
     * ("Osłona pod silnik" / "Aluminium Osłona ..."). straightenPl zachowuje warianty, chroni ręczne.
     * Leci w tle (po odpowiedzi), wyniki widać w zakładce Logi.
     */
    public function translateMissing(Request $request)
    {
        dispatch(function () {
            $composer = app(ProductTranslationComposer::class);
            $this->rawPlQuery()
                ->with('attributeValues.attribute')
                ->chunkById(50, function ($products) use ($composer) {
                    foreach ($products as $product) {
                        $composer->apply($product, 'settings');
                    }
                });
        })->afterResponse();

        return redirect()->back()->with([
            'message' => 'Uruchomiono prostowanie surowych nazw w tle — postęp i wyniki zobaczysz w zakładce Logi.',
        ]);
    }

    /**
     * Produkty źródła z SUROWĄ nazwą PL (wielkie "Osłona" — kanoniczna forma ma małe "osłona"
     * po materiale). Case/diacritic-sensitive przez utf8mb4_bin. To realne "do wyprostowania".
     */
    private function rawPlQuery(): Builder
    {
        $srcId = Source::where('service_class', 'SumpguardSource')->value('id');

        return Product::query()
            ->when($srcId, fn ($q) => $q->where('source_id', $srcId), fn ($q) => $q->whereNotNull('source_id'))
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(name, '$.pl')) AS BINARY) LIKE ?", ['%Osłona%']);
    }

    private function stats(): array
    {
        $productMorph = (new Product())->getMorphClass();

        $lockedProductIds = TranslationOverride::query()
            ->where('translatable_type', $productMorph)
            ->where('field', 'name')
            ->whereIn('source', TranslationOverride::LOCKING_SOURCES)
            ->distinct()
            ->pluck('translatable_id');

        return [
            'products_total'   => (int) Product::whereNotNull('source_id')->count(),
            'products_locked'  => (int) $lockedProductIds->count(),
            'products_pending' => (int) $this->rawPlQuery()->count(), // surowe PL "do wyprostowania"
            'logs_total'       => (int) TranslationLog::count(),
            'logs_unmatched'   => (int) TranslationLog::where('status', TranslationLog::STATUS_UNMATCHED)->count(),
            'last_run_at'      => optional(TranslationLog::latest('id')->value('created_at'))?->format('Y-m-d H:i:s'),
        ];
    }
}
