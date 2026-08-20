<?php

namespace App\Models\Ebay;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;

/**
 * Szablon treści aukcji eBay — własność integracji eBay.
 *
 * Świadomie NIE jest to `App\Models\Template` (tabela `templates`), mimo że mechanika renderu
 * jest ta sama. Tamte szablony zasilają Selly, PrestaShop i OpenCart; gdyby eBay ich używał,
 * każdy tuning pod aukcje (krótszy tytuł, węższy HTML, treść per rynek) przestawiałby sklepy.
 *
 * Render: Blade po zmiennych produktu (`Product::getVariables($locale)`), gdzie locale wynika
 * z rynku szablonu. Dostępne zmienne to kolumny produktu + `attribute_<slug>` dla każdego atrybutu.
 */
class EbayTemplate extends Model
{
    protected $table = 'ebay_templates';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /** Locale treści wynika z rynku — DE i AT po niemiecku, GB po angielsku. */
    public function locale(): string
    {
        return EbayScheme::MARKETPLACE_LOCALE[strtoupper((string) $this->marketplace)] ?? 'en';
    }

    public function getRenderedTitle(Product $product): string
    {
        return $this->render((string) $this->title, $product);
    }

    public function getRenderedDescription(Product $product): string
    {
        return $this->render((string) $this->description, $product);
    }

    /**
     * Zmienne do Blade: to co daje `Product::getVariables()`, ale z wartościami atrybutów
     * w locale RYNKU.
     *
     * `getVariables($locale)` stosuje locale do `name`/`info_*` produktu, ale wartości atrybutów
     * bierze przez `$values->implode('name', ', ')` — a to zwraca tłumaczenie w bieżącym locale
     * aplikacji (`pl`), mimo że `AttributeValue.name` jest translatable i ma komplet języków.
     * Skutek: na niemieckiej aukcji stało „Geschützte Unterbodenelemente: silnik, skrzynię biegów"
     * zamiast „Motor, Getriebe".
     *
     * Poprawiamy to TYLKO dla eBaya — ta sama wada dotyczy `templates` zasilających sklepy,
     * ale zmiana tam przestawiłaby treść na żywych kanałach i jest osobną decyzją.
     *
     * @return array<string,mixed>
     */
    private function variables(Product $product): array
    {
        $locale = $this->locale();
        $vars = $product->getVariables($locale);

        foreach ($product->attributeValues->groupBy('attribute_id') as $values) {
            $attribute = $values->first()?->attribute;
            if (! $attribute) {
                continue;
            }

            $translated = $values
                ->map(function ($v) use ($locale) {
                    $t = trim((string) $v->getTranslation('name', $locale));

                    // Pusty slot językowy → zostaw to, co model daje domyślnie, zamiast
                    // gubić parametr; brak wartości i tak zgłosi walidator kontraktu.
                    return $t !== '' ? $t : trim((string) $v->name);
                })
                ->filter()
                ->implode(', ');

            $vars['attribute_'.\Illuminate\Support\Str::slug($attribute->slug, '_')] = $translated ?: null;
        }

        return $vars;
    }

    /**
     * Blade po zmiennych produktu. Wyjątek renderu (literówka w szablonie, brak zmiennej)
     * NIE może wywrócić listy produktów ani audytu — zwracamy pusty string, a brak treści
     * i tak zgłosi `EbayListingRenderer::problems()` jako blokadę wystawienia.
     */
    private function render(string $template, Product $product): string
    {
        if (trim($template) === '') {
            return '';
        }

        try {
            $html = Blade::render($template, $this->variables($product));
        } catch (\Throwable) {
            return '';
        }

        // Ten sam porządek co w App\Models\Template::cleanHtml — szablony pisze człowiek
        // w edytorze, więc niosą przypadkowe wcięcia i puste linie między znacznikami.
        $html = htmlspecialchars_decode($html);
        $html = preg_replace('/\s+/', ' ', $html) ?? $html;
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;

        return trim($html);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeForMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', strtoupper($marketplace));
    }
}
