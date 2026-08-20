<?php

namespace App\Http\Controllers\Admin\Connect\Marketplace;

use App\Http\Controllers\Admin\Controller;
use App\Models\Ebay\EbayScheme;
use App\Models\Product;
use App\Models\Template;
use App\Services\Ebay\Listing\EbayListingRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Argo Connect → Marketplace → eBay → Szablony.
 *
 * ODSTĘPSTWO OD WZORCA — świadome. W OMS ARGO Allegro ma własny byt `AllegroListingTemplate`:
 * sekcje w 5 układach, obrazki szablonu, paleta tagów, edytor na 26 KB Vue. Powód, dla którego
 * tam tak jest: opis Allegro to natywnie `description.sections[]`, więc układ TRZEBA składać.
 *
 * eBay ma opis jako jeden blok HTML — nie ma czego strukturyzować. A tagi `[opis]` nie miałyby
 * u nas z czego czerpać: `products.info_1` to 7 wpisów PL na 1494 produkty. Realnym źródłem
 * opisu jest tabela `templates` (Blade per locale), która zasila też Selly, PrestaShop i OpenCart.
 *
 * Ten ekran nie duplikuje więc edytora (jest w `admin/templates`), tylko pokazuje to, czego
 * z poziomu edytora nie widać: który schemat eBay używa którego szablonu, jak opis wygląda
 * na konkretnym produkcie i ile pozycji katalogu ten szablon psuje.
 */
class EbayTemplateController extends Controller
{
    /** Audyt katalogu jest kosztowny (render Blade × 1494) — wynik trzymamy godzinę. */
    private const AUDIT_TTL = 3600;

    public function index(): Response
    {
        // Które szematy eBay używają którego szablonu — tego nie widać w `admin/templates`.
        $schemes = EbayScheme::with('template')->get()
            ->filter(fn (EbayScheme $s) => $s->template_id)
            ->groupBy('template_id');

        $templates = Template::orderBy('slug')->get()->map(fn (Template $t) => [
            'id' => $t->id,
            'slug' => $t->slug,
            'locale' => $t->locale,
            'has_title' => trim((string) $t->title) !== '',
            'has_description' => trim((string) $t->description) !== '',
            'schemes' => $schemes->get($t->id, collect())
                ->map(fn (EbayScheme $s) => ['id' => $s->id, 'name' => $s->name, 'marketplace' => $s->marketplace])
                ->values(),
            'edit_url' => route('crafter.templates.edit', $t->id),
            'audit' => Cache::get($this->auditKey($t->id)),
        ]);

        return Inertia::render('Connect/Marketplace/Ebay/Templates/Index', [
            'templates' => $templates,
            'schemesUrl' => route('crafter.connect.marketplace.ebay.schemes.index'),
        ]);
    }

    /**
     * Podgląd treści na konkretnym produkcie — dokładnie to, co pójdzie na aukcję.
     * Bez `product_id` bierze pierwszy produkt pasujący do frazy (albo pierwszy z katalogu).
     */
    public function preview(Request $request, EbayListingRenderer $renderer): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:templates,id'],
            'product_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $template = Template::findOrFail($data['template_id']);

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

        try {
            $title = $renderer->title($template, $product);
            $description = $renderer->description($template, $product);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Szablon nie wyrenderował się: '.$e->getMessage()], 422);
        }

        return response()->json([
            'product' => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->getTranslation('name', 'pl'),
            ],
            'template' => ['slug' => $template->slug, 'locale' => $template->locale],
            'title' => $title['title'],
            'title_length' => mb_strlen($title['title']),
            'title_max' => EbayListingRenderer::TITLE_MAX,
            'title_truncated' => $title['truncated'],
            'title_original_length' => $title['original_length'],
            'description' => $description,
            'images' => $renderer->images($product),
            'problems' => $renderer->problems($template, $product),
        ]);
    }

    /**
     * Audyt całego katalogu tym szablonem — ile produktów wyszłoby wadliwie, ZANIM cokolwiek
     * wystawimy. To ta sama logika co `ebay:render-preview --audit`, tylko podana w UI.
     */
    public function audit(Request $request, EbayListingRenderer $renderer): JsonResponse
    {
        $data = $request->validate(['template_id' => ['required', 'integer', 'exists:templates,id']]);
        $template = Template::findOrFail($data['template_id']);

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

    private function auditKey(int $templateId): string
    {
        return "ebay.template.audit.{$templateId}";
    }
}
