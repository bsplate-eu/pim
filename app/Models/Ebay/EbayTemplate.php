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
            $html = Blade::render($template, $product->getVariables($this->locale()));
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
