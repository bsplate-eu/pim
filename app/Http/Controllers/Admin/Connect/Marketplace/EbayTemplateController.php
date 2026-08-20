<?php

namespace App\Http\Controllers\Admin\Connect\Marketplace;

use App\Http\Controllers\Admin\Controller;
use App\Models\Ebay\EbayScheme;
use App\Models\Ebay\EbayTemplate;
use App\Models\Product;
use App\Services\Ebay\Listing\EbayAuctionSkin;
use App\Services\Ebay\Listing\EbayListingRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Argo Connect → Marketplace → eBay → Szablony.
 *
 * Szablony treści aukcji są WŁASNOŚCIĄ integracji eBay (`ebay_templates`), nie współdzielone
 * z szablonami sklepowymi (`templates` → Selly / PrestaShop / OpenCart). Dzięki temu treść
 * pod aukcje można stroić — krótszy tytuł, węższy HTML, zapisy per rynek — nie ruszając sklepów.
 *
 * Jeden szablon = jeden rynek. Ten sam język bywa na dwóch rynkach (DE i AT), ale treść może
 * się różnić, więc od powielania jest „Kopiuj", które tworzy niezależną kopię.
 */
class EbayTemplateController extends Controller
{
    /** Audyt katalogu jest kosztowny (render Blade × 1494) — wynik trzymamy godzinę. */
    private const AUDIT_TTL = 3600;

    public function index(): Response
    {
        $schemes = EbayScheme::whereNotNull('template_id')->get()->groupBy('template_id');

        $templates = EbayTemplate::orderBy('marketplace')->orderBy('name')->get()
            ->map(fn (EbayTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'marketplace' => $t->marketplace,
                'locale' => $t->locale(),
                'enabled' => $t->enabled,
                'title' => $t->title,
                'description' => $t->description,
                'has_title' => trim((string) $t->title) !== '',
                'has_description' => trim((string) $t->description) !== '',
                'schemes' => $schemes->get($t->id, collect())
                    ->map(fn (EbayScheme $s) => ['id' => $s->id, 'name' => $s->name, 'marketplace' => $s->marketplace])
                    ->values(),
                'audit' => Cache::get($this->auditKey($t->id)),
            ]);

        return Inertia::render('Connect/Marketplace/Ebay/Templates/Index', [
            'templates' => $templates,
            'marketplaces' => array_keys(EbayScheme::MARKETPLACE_LOCALE),
            'marketplaceLocales' => EbayScheme::MARKETPLACE_LOCALE,
            'schemesUrl' => route('crafter.connect.marketplace.ebay.schemes.index'),
            // Zmienne dostępne w szablonie: kolumny produktu + atrybuty (attribute_<slug>).
            'variables' => $this->availableVariables(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $template = EbayTemplate::create($this->validated($request));

        return back()->with('success', "Szablon „{$template->name}” utworzony.");
    }

    public function update(Request $request, EbayTemplate $template): RedirectResponse
    {
        $template->update($this->validated($request));
        Cache::forget($this->auditKey($template->id));   // treść się zmieniła — stary audyt kłamie

        return back()->with('success', "Szablon „{$template->name}” zapisany.");
    }

    /** Kopia do rozjechania — tak obsługujemy „ten sam język, inny rynek" (DE → AT). */
    public function duplicate(EbayTemplate $template): RedirectResponse
    {
        $copy = $template->replicate();
        $copy->name = $template->name.' (kopia)';
        $copy->save();

        return back()->with('success', "Utworzono kopię „{$copy->name}” — zmień jej rynek i treść.");
    }

    public function destroy(EbayTemplate $template): RedirectResponse
    {
        // Schemat bez szablonu przestaje być gotowy do wystawiania — mówimy o tym wprost,
        // zamiast zostawiać cichą dziurę do odkrycia przy pierwszej próbie publikacji.
        $used = EbayScheme::where('template_id', $template->id)->pluck('name');
        $template->delete();

        return back()->with(
            $used->isEmpty() ? 'success' : 'error',
            $used->isEmpty()
                ? 'Szablon usunięty.'
                : "Szablon usunięty. Schematy zostały bez treści: {$used->implode(', ')}."
        );
    }

    /**
     * Podgląd treści na konkretnym produkcie — dokładnie to, co pójdzie na aukcję.
     * Bez `product_id` bierze pierwszy produkt pasujący do frazy (albo pierwszy z katalogu).
     */
    public function preview(Request $request, EbayListingRenderer $renderer): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:ebay_templates,id'],
            'product_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $template = EbayTemplate::findOrFail($data['template_id']);

        $product = Product::with(['attributeValues.attribute', 'media'])
            ->when(! empty($data['product_id']), fn ($q) => $q->whereKey($data['product_id']))
            ->when(empty($data['product_id']) && ! empty($data['search']), function ($q) use ($data) {
                $s = $data['search'];
                $q->where(fn ($w) => $w->where('product_code', 'like', "%{$s}%")->orWhere('name->pl', 'like', "%{$s}%"));
            })
            ->orderBy('id')
            ->first();

        if (! $product) {
            return response()->json(['error' => 'Nie znalazłem produktu do podglądu.'], 422);
        }

        $title = $renderer->title($template, $product);
        $description = $renderer->description($template, $product);
        $images = $renderer->images($product);

        return response()->json([
            // Podgląd aukcji: nasza treść wpuszczona w szablon BaseLinkera, żeby było widać
            // to, co zobaczy kupujący — z logo, galerią i tabelą parametrów z list opisu.
            'auction_html' => EbayAuctionSkin::wrap(
                (string) $template->marketplace,
                $title['title'],
                (string) $product->product_code,
                $images,
                $description,
            ),
            'product' => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->getTranslation('name', 'pl'),
            ],
            'template' => ['id' => $template->id, 'name' => $template->name, 'marketplace' => $template->marketplace],
            'title' => $title['title'],
            'title_length' => mb_strlen($title['title']),
            'title_max' => EbayListingRenderer::TITLE_MAX,
            'title_truncated' => $title['truncated'],
            'title_original_length' => $title['original_length'],
            'description' => $description,
            'images' => $images,
            'problems' => $renderer->problems($template, $product),
        ]);
    }

    /**
     * Audyt całego katalogu tym szablonem — ile produktów wyszłoby wadliwie, ZANIM cokolwiek
     * wystawimy. Ta sama logika co `ebay:render-preview --audit`, tylko podana w UI.
     */
    public function audit(Request $request, EbayListingRenderer $renderer): JsonResponse
    {
        $data = $request->validate(['template_id' => ['required', 'integer', 'exists:ebay_templates,id']]);
        $template = EbayTemplate::findOrFail($data['template_id']);

        $counts = [];
        $samples = [];
        $checked = 0;
        $clean = 0;

        Product::with(['attributeValues.attribute', 'media'])
            ->orderBy('id')
            ->chunk(200, function ($chunk) use ($renderer, $template, &$counts, &$samples, &$checked, &$clean) {
                foreach ($chunk as $product) {
                    $checked++;
                    $problems = $renderer->problems($template, $product);
                    if ($problems === []) {
                        $clean++;

                        continue;
                    }
                    foreach ($problems as $p) {
                        // „tytuł przycięty z 93 do 80" → kubełek bez liczb, inaczej każdy wpis byłby osobny.
                        $key = preg_replace('/\d+/', 'N', $p);
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                        $samples[$key] ??= $product->product_code;
                    }
                }
            });

        arsort($counts);
        $result = [
            'checked' => $checked,
            'clean' => $clean,
            'problems' => collect($counts)->map(fn ($n, $k) => ['label' => $k, 'count' => $n, 'sample' => $samples[$k]])->values(),
            'at' => now()->toIso8601String(),
        ];

        Cache::put($this->auditKey($template->id), $result, self::AUDIT_TTL);

        return response()->json($result);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'marketplace' => ['required', 'string', 'max:16'],
            'title' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:200000'],
            'enabled' => ['required', 'boolean'],
        ]);
    }

    /**
     * Podpowiedzi zmiennych do edytora: kolumny produktu, które mają sens w treści,
     * plus `attribute_<slug>` dla każdego atrybutu w katalogu.
     *
     * @return list<array{name:string, label:string}>
     */
    private function availableVariables(): array
    {
        $base = [
            ['name' => 'name', 'label' => 'Nazwa produktu (w locale rynku)'],
            ['name' => 'product_code', 'label' => 'SKU / kod produktu'],
            ['name' => 'ean', 'label' => 'EAN'],
            ['name' => 'info_1', 'label' => 'Opis (rzadko wypełniony)'],
            ['name' => 'width', 'label' => 'Szerokość'],
            ['name' => 'weight', 'label' => 'Waga'],
        ];

        $attributes = \App\Models\Attribute::orderBy('order')->orderBy('id')->get()
            ->map(fn ($a) => [
                'name' => 'attribute_'.\Illuminate\Support\Str::slug($a->slug, '_'),
                'label' => (string) $a->name,
            ])
            ->all();

        return array_merge($base, $attributes);
    }

    private function auditKey(int $templateId): string
    {
        return "ebay.template.audit.{$templateId}";
    }
}
