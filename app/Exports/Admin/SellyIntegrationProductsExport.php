<?php

namespace App\Exports\Admin;

use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\IntegrationSource;
use App\Models\PricelistProduct;
use App\Services\ProductPhraseClassifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Throwable;

class SellyIntegrationProductsExport implements FromCollection, WithHeadings, WithCustomCsvSettings
{
    /** Stała ilość magazynowa w feedzie — PIM nie trzyma realnych stanów. */
    public const DEFAULT_QUANTITY = 100;

    /** Kategoria „śmietnik" X dla ścieżek spoza mapy (nowe modele) — ręczne sortowanie w Selly. */
    public const UNMAPPED_PATH = '1. XXX';
    public const UNMAPPED_ID   = 401;

    /**
     * Priorytet typu osłony w kategorii (element z klasyfikatora). Niżej = wcześniej.
     * silnik → skrzynia → reszta. Reszta bez wpisu = 90, nierozpoznane = 95.
     */
    private const TYPE_PRIORITY = [
        'silnika' => 1,
        'silnika i skrzyni biegów' => 2,
        'skrzyni biegów' => 3,
        'skrzyni biegów i reduktora' => 4,
        'reduktora' => 5,
        'chłodnicy' => 6,
        'zbiornika paliwa' => 7,
        'dyferencjału' => 8,
        'AdBlue' => 9,
        'DPF' => 10,
        'EGR' => 11,
        'katalizatora' => 12,
        'przedniego zderzaka' => 13,
        'filtra paliwa' => 14,
        'akumulatora' => 15,
        'skrzynki transferowej' => 16,
        'czujnika tylnego wahacza' => 17,
    ];

    /** Metadane sortowania per wiersz: Kod_importu => [mat, year, type, set, code]. */
    private array $sortMeta = [];

    private ProductPhraseClassifier $classifier;

    /**
     * Prefiks „Kod importu". Sklep Selly był zbudowany z TEGO PIM jako opis##{external_id},
     * więc emitujemy ten sam format → Selly dopasowuje po „Kod importu" i AKTUALIZUJE,
     * zamiast tworzyć duplikaty.
     */
    public const KOD_IMPORTU_PREFIX = 'opis##';

    /** Kategoria zbiorcza dla produktów BEZ kategorii (istniejąca kategoria Selly „PIM"). */
    public const FALLBACK_CATEGORY_PATH = 'PIM';
    public const FALLBACK_CATEGORY_ID   = 640;

    private Collection $prices;

    /** Mapa: ścieżka kategorii (np. „BMW|BMW X5") => realne ID kategorii w Selly. */
    private array $categoryMap;

    /**
     * Nadrzędna mapa per-produkt: external_id => ID kategorii w Selly.
     * Utrwala RĘCZNE ułożenie produktów w sklepie — ma pierwszeństwo przed drzewem/`categoryMap`,
     * więc kolejne update'y feedu nie cofają decyzji ze sklepu. Źródło: `config/selly.category_overrides`.
     */
    private array $categoryOverrides;

    /**
     * @param array|null $onlyExternalIds TEST: ogranicz eksport do wskazanych external_id (push próbny).
     */
    public function __construct(private Integration $integration, private ?array $onlyExternalIds = null)
    {
        $this->integration->load('integrationSources.template');
        $this->categoryMap = (array) config('selly.category_map', []);
        $this->categoryOverrides = (array) config('selly.category_overrides', []);
        $this->classifier = app(ProductPhraseClassifier::class);
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection(): Collection
    {
        $result = collect();

        $this->integration->integrationSources->each(function ($integrationSource) use (&$result) {
            if (!$integrationSource->template || !$integrationSource->pricelist) {
                return;
            }

            app()->setLocale($integrationSource->template->locale);
            $this->prices = PricelistProduct::where('pricelist_id', $integrationSource->pricelist->id)
                ->selectRaw('product_id, ' . PricelistProduct::EXPORT_PRICE_SQL . ' as price')
                ->get()->keyBy('product_id');

            $query = IntegrationProduct::with('product.media', 'product.attributeValues.attribute', 'product.categories')
                ->where('integration_id', $this->integration->id)
                ->where('integration_source_id', $integrationSource->id);

            if ($this->onlyExternalIds) { // TEST: tylko wskazane produkty (push próbny)
                $query->whereHas('product', fn ($q) => $q->whereIn('external_id', $this->onlyExternalIds));
            }

            $integration_source_result = $query->get()
                ->map(fn (IntegrationProduct $model) => $this->map($model, $integrationSource))
                ->filter(fn ($row) => !empty($row)); // pomiń wiersze bez produktu

            $result = $result->merge($integration_source_result);
        });

        // Pakiety/zestawy NIE idą do Selly (decyzja: nie eksportujemy pakietów).
        $result = $result->reject(fn ($r) => !empty($this->sortMeta[$r['Kod_importu']]['set']))->values();

        // „Kolejność w kategorii": materiał (stal→alu) → rocznik malejąco → typ (silnik→skrzynia→reszta).
        return $this->assignCategoryOrder($result);
    }

    /**
     * Numeruje „Kolejność w kategorii" 1..N w każdej kategorii wg reguły sklepu:
     * materiał (stal przed alu) → rocznik malejąco → typ osłony (silnik→skrzynia→reszta) → kod.
     */
    private function assignCategoryOrder(Collection $rows): Collection
    {
        $ordered = collect();
        // Grupujemy po ID kategorii (ścieżka jest teraz pusta w feedzie).
        foreach ($rows->groupBy('Kategoria_ID') as $catRows) {
            $sorted = $catRows->sort(function ($a, $b) {
                $ma = $this->sortMeta[$a['Kod_importu']];
                $mb = $this->sortMeta[$b['Kod_importu']];
                return [$ma['mat'], -$ma['year'], $ma['type'], $ma['code']]
                   <=> [$mb['mat'], -$mb['year'], $mb['type'], $mb['code']];
            })->values();

            $pos = 0;
            foreach ($sorted as $row) {
                $row['Kolejnosc_w_kategorii'] = ++$pos;
                $ordered->push($row);
            }
        }
        return $ordered;
    }

    private function map(IntegrationProduct $model, IntegrationSource $integrationSource)
    {
        $product = $model->getOverridedProduct() ?? $model->product;
        if (!$product) {
            return [];
        }
        $price = $this->getPrice($product, $integrationSource->multiplier);

        $images = $product->getMedia('images')
            ->filter(fn ($m) => ($m->getCustomProperty('enabled') ?? true) !== false)
            ->sortBy('order_column')
            ->pluck('original_url')
            ->toArray();

        $name = $this->safeTemplateRender($integrationSource, 'getRenderedTitle', $product, (string)($product->name ?? $product->product_code ?? ''));
        $description = $this->safeTemplateRender($integrationSource, 'getRenderedDescription', $product, (string)($product->description ?? ''));
        $description_short = $this->safeTemplateRender($integrationSource, 'getRenderedShortDescription', $product, '');

        [$catPath, $catId] = $this->resolveCategory($product);

        $data = [
            "Kod_importu" => self::KOD_IMPORTU_PREFIX . $product->external_id,
            "Producent" => $this->integration->manufacturer,
            "Kod_producenta" => $product->product_code,
            "Nazwa_produktu" => $this->prepareName($name),
            "Nazwa dodatkowa" => $this->buildNazwaDodatkowa($product),
            "Tekst promocyjny" => $this->buildPromoText($product),
            // Ścieżka PUSTA — Selly NIE tworzy kategorii po nazwie „Marka|Model" (puste wydmuszki 754+).
            // Kategoria decydowana WYŁĄCZNIE przez „Kategoria ID" (wymaga włączonej kolumny c_7 w integratorze).
            "Kategoria_sciezka" => '',
            "Kategoria_ID" => $catId,
            "Opis_HTML" => $description,
            "Opis_dodatkowy_HTML" => $description_short,
            "Cena_brutto" => $price,
            "Stawka VAT" => (string)$integrationSource->tax,
            "Ilosc" => self::DEFAULT_QUANTITY,
            "Wyświetlanie" => (int)$product->enabled,
            "Zdjecie_glowne" => array_shift($images),
            "Zdjecia" => implode(',',$images),
            "Kolejnosc_w_kategorii" => 0, // uzupełniane w assignCategoryOrder()
        ];

        // metadane do sortowania w kategorii (materiał / rocznik / typ / zestaw)
        $this->sortMeta[$data['Kod_importu']] = $this->buildSortMeta($product);

        return $data;
    }

    /**
     * Wylicza klucze sortowania kolejności: materiał (0 stal, 1 alu), rocznik (year-start),
     * typ osłony (priorytet z klasyfikatora), zestaw (0/1 — pakiety i tak są odrzucane) i kod.
     */
    private function buildSortMeta($product): array
    {
        $code   = (string) $product->product_code;
        $plName = (string) $product->getTranslation('name', 'pl', false);

        $isAlu = (bool) (preg_match('/alu/i', $code) || preg_match('/alumin/i', $plName));
        $isSet = (bool) (strpos($code, '+') !== false || preg_match('/set/i', $code) || preg_match('/zestaw|pakiet/i', $plName));

        $year = 0;
        foreach ($product->attributeValues as $av) {
            if ($av->attribute?->slug === 'year-start') {
                $year = (int) $av->getTranslation('name', 'pl', false);
            }
        }

        $cls  = $this->classifier->classify($plName);
        $type = $cls ? (self::TYPE_PRIORITY[$cls['element']] ?? 90) : 95;

        return ['mat' => $isAlu ? 1 : 0, 'year' => $year, 'type' => $type, 'set' => $isSet ? 1 : 0, 'code' => $code];
    }

    /**
     * Zwraca [ścieżka, ID kategorii]. Realne ID Selly z mapy; brak kategorii → „PIM"/640;
     * ścieżka spoza mapy (nowa kategoria) → md5 (Selly utworzy po ścieżce).
     *
     * @return array{0:string,1:int|string}
     */
    private function resolveCategory($product): array
    {
        // Nadrzędnie: ręczne ułożenie ze sklepu (external_id => ID kategorii). Ma pierwszeństwo
        // nad drzewem/`categoryMap` — feed nigdy nie cofa decyzji zrobionej ręcznie w Selly.
        $override = $this->categoryOverrides[$product->external_id] ?? null;
        if ($override !== null) {
            return ['', (int) $override];
        }

        $path = $product->categories->implode('name', '|');
        if ($path === '') {
            return [self::FALLBACK_CATEGORY_PATH, self::FALLBACK_CATEGORY_ID];
        }
        // Ścieżka spoza mapy (nowy model, którego Selly nie ma) → kategoria X „1. XXX" do ręcznego sortowania.
        $id = $this->categoryMap[$path] ?? null;
        if ($id === null) {
            return [self::UNMAPPED_PATH, self::UNMAPPED_ID];
        }
        return [$path, $id];
    }

    /**
     * „Kod produktu: {kod} | Grubość {stali|aluminium}: {width} mm".
     * width = grubość blachy (zweryfikowane: 96% zgodności z danymi w Selly). width=0 → pomiń grubość.
     * Wariant aluminiowy rozpoznany po „ALU" w kodzie.
     */
    private function buildPromoText($product): string
    {
        $code  = (string) $product->product_code;
        $parts = ["Kod produktu: {$code}"];

        $w = (float) $product->width;
        if ($w > 0) {
            $material = preg_match('/alu/i', $code) ? 'aluminium' : 'stali';
            $ww = rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.'); // 2.00 -> "2", 2.50 -> "2.5"
            $parts[] = "Grubość {$material}: {$ww} mm";
        }

        return implode(' | ', $parts);
    }

    /**
     * „Nazwa dodatkowa" = „Pasuje do silników: {engine}" z atrybutu `engine` (98% pokrycia).
     * Wiele wartości engine → złączone przecinkiem. Brak engine → null (pole puste — Selly go nie ruszy).
     * Lekkie czyszczenie: złóż podwójne spacje/przecinki (dane źródłowe bywają „1,6 ,1,9 D").
     */
    private function buildNazwaDodatkowa($product): ?string
    {
        $engines = $product->attributeValues
            ->filter(fn ($av) => $av->attribute?->slug === 'engine')
            ->map(fn ($av) => trim((string) $av->getTranslation('name', app()->getLocale(), false)))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values();

        if ($engines->isEmpty()) {
            return null;
        }

        $text = $engines->implode(', ');
        $text = preg_replace('/\s*,\s*(?=,)/u', '', $text); // usuń puste człony między przecinkami
        $text = preg_replace('/\s{2,}/u', ' ', $text);      // złóż podwójne spacje
        $text = trim($text, " ,");

        return $text === '' ? null : "Pasuje do silników: {$text}";
    }

    private function prepareName(string $name)
    {
        $name = htmlspecialchars_decode($name);
        $name = preg_replace('/[<>;=#{}]/', '-', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return Str::limit($name, 128, '');
    }

    private function getPrice($product, $multiplier = 1)
    {
        $price = $this->prices->get($product->id)->price ?? 0;
        return ceil($price * $multiplier);
    }

    private function safeTemplateRender(IntegrationSource $integrationSource, string $method, mixed $product, string $fallback = ''): string
    {
        $template = $integrationSource->template;
        if (!$template || !method_exists($template, $method)) {
            return $fallback;
        }

        try {
            set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
                if (!(error_reporting() & $severity)) {
                    return false;
                }
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });

            return (string)$template->{$method}($product);
        } catch (Throwable $e) {
            report($e);
            return $fallback;
        } finally {
            restore_error_handler();
        }
    }

    public function headings(): array
    {
        return [
            "Kod importu",
            "Producent",
            "Kod producenta",
            "Nazwa produktu",
            "Nazwa dodatkowa",
            "Tekst promocyjny",
            "Kategoria ścieżka",
            "Kategoria ID",
            "Opis HTML",
            "Opis dodatkowy HTML",
            "Cena brutto",
            "Stawka VAT",
            "Ilość",
            "Wyświetlanie",
            "Zdjęcie główne",
            "Zdjęcia",
            "Kolejność w kategorii",
        ];
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
            'use_bom' => true,
        ];
    }
}
