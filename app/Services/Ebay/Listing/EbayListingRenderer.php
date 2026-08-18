<?php

namespace App\Services\Ebay\Listing;

use App\Models\Product;
use App\Models\Template;

/**
 * Renderer treści oferty eBay: tytuł + opis + zdjęcia dla konkretnego produktu i rynku.
 *
 * ODSTĘPSTWO OD WZORCA (świadome). W OMS ARGO Allegro dostał własny byt `AllegroListingTemplate`
 * — sekcje z tagami [nazwa]/[opis]/[cena] i osobny edytor. U nas budowanie takiego drugiego
 * silnika byłoby błędem: mamy już `App\Models\Template` (tabela `templates`, jeden wiersz na
 * locale), renderowany Bladem przez `Product::getVariables($locale)`, który zasila Selly,
 * PrestaShop i OpenCart. Te same szablony dają gotowe opisy DE/FR/ES/CS/SK/EN/LV/ET.
 *
 * Powody, dla których reużywamy zamiast kopiować:
 *  • `products.info_1` (opis) jest u nas praktycznie PUSTE — 7 wpisów PL na 1494 produkty.
 *    Tagi [opis]/[opis_dodatkowy*] z wzorca Allegro nie miałyby z czego czerpać. Realnym
 *    źródłem opisu są ATRYBUTY (marka, model, roczniki, materiał) złożone szablonem Blade.
 *  • jedno źródło opisu = aukcja eBay mówi to samo co sklep; dwa silniki rozjechałyby się
 *    przy pierwszej zmianie treści.
 *  • `Template.locale` rozwiązuje wielojęzyczność za darmo — a to była największa różnica
 *    między nami a wzorcem (Allegro jest jednojęzyczne).
 *
 * Schemat wystawiania (etap C) wskazuje `template_id`; tu tylko renderujemy i dociosujemy
 * wynik do wymagań eBaya (limit tytułu, dozwolony HTML).
 */
class EbayListingRenderer
{
    /** Twardy limit tytułu aukcji eBay. */
    public const TITLE_MAX = 80;

    /** Maksymalna liczba zdjęć w ofercie eBay. */
    public const IMAGES_MAX = 24;

    /**
     * HTML, który zostawiamy w opisie. eBay dopuszcza szeroki podzbiór, ale odrzuca treści
     * aktywne (script/iframe/form) — zamiast czarnej listy trzymamy białą, bo szablony pisze
     * człowiek i łatwiej o wklejkę z edytora niż o świadomy atak.
     */
    private const ALLOWED_TAGS = '<div><p><br><h1><h2><h3><h4><ul><ol><li><b><strong><i><em><u><span><table><thead><tbody><tr><td><th><hr>';

    /**
     * Tytuł aukcji: wyrenderowany szablonem, spłaszczony do czystego tekstu i przycięty do 80 znaków.
     *
     * @return array{title:string, truncated:bool, original_length:int}
     */
    public function title(Template $template, Product $product): array
    {
        $raw = $this->flatten($template->getRenderedTitle($product));

        return [
            'title' => $this->truncateTitle($raw),
            'truncated' => mb_strlen($raw) > self::TITLE_MAX,
            'original_length' => mb_strlen($raw),
        ];
    }

    /** Opis aukcji: wyrenderowany szablonem, obcięty do dozwolonego HTML. */
    public function description(Template $template, Product $product): string
    {
        return trim(strip_tags($template->getRenderedDescription($product), self::ALLOWED_TAGS));
    }

    /**
     * Adresy zdjęć produktu. eBay przyjmuje URL zewnętrzne, ale muszą być publicznie osiągalne
     * — lokalnie MediaLibrary zwraca `http://pim.test/...`, co na produkcji nie zadziała.
     * Weryfikacja adresów należy do publikacji (etap E), tu tylko je zbieramy w kolejności.
     *
     * @return list<string>
     */
    public function images(Product $product, int $limit = self::IMAGES_MAX): array
    {
        return $product->getMedia('images')
            ->sortBy('order_column')
            ->take($limit)
            ->map(fn ($m) => (string) $m->original_url)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Czy da się z tego zbudować ofertę? Zwraca listę braków (pusta = komplet).
     * Wołane przez podgląd wystawiania (etap D), żeby braki było widać PRZED wysyłką.
     *
     * @return list<string>
     */
    public function problems(Template $template, Product $product): array
    {
        $problems = [];

        $title = $this->title($template, $product);
        if (trim($title['title']) === '') {
            $problems[] = 'pusty tytuł';
        } elseif ($title['truncated']) {
            $problems[] = "tytuł przycięty z {$title['original_length']} do ".self::TITLE_MAX.' znaków';
        }

        if (trim(strip_tags($this->description($template, $product))) === '') {
            $problems[] = 'pusty opis';
        }

        if ($this->images($product) === []) {
            $problems[] = 'brak zdjęć';
        }

        return $problems;
    }

    /** HTML/encje/wielokrotne spacje → jedna linia czystego tekstu (tytuł eBay to plain text). */
    private function flatten(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Przytnij tytuł do limitu eBaya, ucinając na granicy słowa — „…Mito (2008-20" wygląda
     * na aukcji jak błąd, „…Alfa Romeo Mito" jak świadomy skrót.
     */
    private function truncateTitle(string $title): string
    {
        if (mb_strlen($title) <= self::TITLE_MAX) {
            return $title;
        }

        $cut = mb_substr($title, 0, self::TITLE_MAX);
        $lastSpace = mb_strrpos($cut, ' ');

        // Ucinamy na spacji tylko wtedy, gdy nie zjada to połowy tytułu (np. jedno długie słowo).
        if ($lastSpace !== false && $lastSpace > self::TITLE_MAX * 0.6) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B-–—,;:(");
    }
}
