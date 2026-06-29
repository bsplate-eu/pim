<?php

namespace App\Sources;

use App\Mail\SumpguardEmail;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Models\Source;
use App\Models\TranslationOverride;
use App\Settings\GeneralSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SumpguardSource extends BaseSource implements SourceInterface
{

    /**
     * @var array|array[]
     */
    private array $price_ranges;
    private array $product_translations;
    private Collection $products;

    private $language_country_codes = [
        'cs' => 'cz',
    ];

    private $source_attributes = [
        'make',
        'model',
        'year_start',
        'year_stop',
        'oil',
        'engine',
        'gearbox',
        'protection',
    ];
    private Collection $attributes;
    private mixed $locales;
    private Category $root_category;

    public function __construct(protected Source $source)
    {
        parent::__construct($source);

        $this->locales = app(GeneralSettings::class)->available_locales;

        $parentNameDefault = 'Sumpguard';
        $default = app()->getLocale() ?? 'en';

        $this->root_category = Category::query()->firstOrCreate(
            [
                'parent_id' => null,
                "name->{$default}" => $parentNameDefault,
            ],
            [
                'parent_id' => null,
                'name'      => $this->buildNameTranslations($parentNameDefault),
            ]
        );

    }

    private function synchronizeAttributes()
    {
        $has_new_attributes = false;
        $attributes = Attribute::with('values')->get()->keyBy('slug');

        collect($this->source_attributes)->each(function ($source_attribute) use ($attributes, &$has_new_attributes) {
            $slug = Str::slug($source_attribute);
            if (!$attributes->get($slug)) {
                $has_new_attributes = true;
                $name = Str::title(str_replace('_', ' ', $source_attribute));

                // Tylko domyślny locale — pozostałe sloty zostawiamy puste żeby matryca/auto-translate je wypełniła.
                // Wcześniej kopiowaliśmy PL do wszystkich 14 locale — to wciskało polski do slotów DE/FR/etc.
                $default = app()->getLocale() ?: 'en';
                Attribute::create([
                    'name' => [$default => $name],
                    'slug' => $slug
                ]);

            }
        });


        if ($has_new_attributes) {
            $attributes = Attribute::with('values')->get()->keyBy('slug');
        }

        $this->attributes = $attributes;
    }


    public function synchronize()
    {
        $this->synchronizeAttributes();

        $this->products = Product::select(['id', 'external_id'])->get()->keyBy('external_id');
        $locales = $this->locales;
        $base_locale = array_shift($locales);

        foreach ($locales as $locale) {
            $json = $this->getJsonData($locale);
            $this->getTranslations($json, $locale);
        }

        $json = $this->getJsonData($base_locale);
        $this->getProducts($json, $base_locale);

        $this->getPrices($json);

        $this->compare();


    }

    /**
     * Dociąga brakujące zdjęcia dla produktów tego źródła, które nie mają
     * żadnych mediów (np. po nieudanym wcześniejszym przebiegu importu).
     * Nie tworzy nowych produktów — działa wyłącznie na istniejących.
     *
     * @param callable|null $log opcjonalny callback(string) do raportowania postępu
     * @return array{products_without_media:int, processed:int, attached:int, skipped:int}
     */
    public function backfillMissingImages(?callable $log = null): array
    {
        $locales = $this->locales;
        $base_locale = array_shift($locales);

        $feed = $this->getJsonData($base_locale)->keyBy('id');

        $stats = [
            'products_without_media' => 0,
            'processed'              => 0,
            'attached'               => 0,
            'skipped'                => 0,
        ];

        Product::query()
            ->where('source_id', $this->source->id)
            ->whereDoesntHave('media')
            ->select(['id', 'external_id'])
            ->chunkById(50, function ($products) use ($feed, &$stats, $log) {
                foreach ($products as $product) {
                    $stats['products_without_media']++;

                    $item = $feed->get($product->external_id);
                    if (!$item || empty($item['images'])) {
                        $stats['skipped']++;
                        $log && $log("POMINIETO id={$product->id} external_id={$product->external_id} (brak w feedzie lub brak zdjec)");
                        continue;
                    }

                    $this->getImages($product, $item);

                    $count = $product->getMedia('images')->count();
                    $stats['processed']++;
                    $stats['attached'] += $count;
                    $log && $log("OK id={$product->id} external_id={$product->external_id} -> podpieto {$count} zdj.");
                }
            });

        return $stats;
    }

    /**
     * Kasuje i pobiera NA NOWO zdjęcia dla produktów tego źródła, które mają
     * media z wadliwą (podwójną) nazwą rozszerzenia (np. "...jpg.jpg") — żeby
     * zapisać je z czystą, pojedynczą nazwą i naprawić serwowanie (404).
     *
     * @param callable|null $log opcjonalny callback(string) do raportowania postępu
     * @return array{products:int, cleared:int, attached:int, skipped:int}
     */
    public function redownloadBadlyNamedImages(?callable $log = null): array
    {
        $locales = $this->locales;
        $base_locale = array_shift($locales);

        $feed = $this->getJsonData($base_locale)->keyBy('id');

        $stats = ['products' => 0, 'cleared' => 0, 'attached' => 0, 'skipped' => 0];

        $sourceProductIds = Product::where('source_id', $this->source->id)->pluck('id');

        $productIds = \Spatie\MediaLibrary\MediaCollections\Models\Media::query()
            ->where('model_type', Product::class)
            ->where('collection_name', 'images')
            ->whereIn('model_id', $sourceProductIds)
            ->where(function ($q) {
                foreach (['%.jpg.jpg', '%.jpeg.jpeg', '%.jpg.jpeg', '%.jpeg.jpg', '%.png.png', '%.gif.gif', '%.webp.webp'] as $pattern) {
                    $q->orWhere('file_name', 'like', $pattern);
                }
            })
            ->distinct()
            ->pluck('model_id');

        foreach (Product::whereIn('id', $productIds)->get(['id', 'external_id']) as $product) {
            $stats['products']++;

            $item = $feed->get($product->external_id);
            if (!is_array($item) || empty($item['images'])) {
                $stats['skipped']++;
                $log && $log("POMINIETO id={$product->id} external_id={$product->external_id} (brak w feedzie)");
                continue;
            }

            $before = $product->getMedia('images')->count();
            $product->clearMediaCollection('images'); // usuwa rekordy + pliki + konwersje
            $stats['cleared'] += $before;

            $this->getImages($product, $item); // pobiera ponownie z czystą nazwą

            $after = $product->getMedia('images')->count();
            $stats['attached'] += $after;
            $log && $log("OK id={$product->id} external_id={$product->external_id} -> skasowano {$before}, pobrano {$after}");
        }

        return $stats;
    }


    private function vauxhallClear($string)
    {
        return str_replace('Vauxhall', 'Opel', $string);
    }


    private function getName($item, $locale)
    {
        $names = [
            $locale => $this->vauxhallClear($item['name'])
        ];

        if (isset($this->product_translations[$item['id']])) {
            foreach ($this->product_translations[$item['id']]['name'] as $locale => $value) {
                $names[$locale] = $value;
            }
        }

        return $names;
    }

    private function validateImageUrl(string $url): bool
    {
        try {
            $response = Http::head($url);

            if (!$response->successful()) {
                return false;
            }

            $contentType = $response->header('Content-Type');
            return str_starts_with($contentType, 'image/');

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Buduje czystą nazwę pliku z POJEDYNCZYM rozszerzeniem.
     * Feed Sumpguard bywa zwraca URL-e z podwójnym rozszerzeniem (np. "...jpg.jpg"),
     * przez co serwer WWW odrzuca takie pliki (404). Normalizujemy do jednego rozszerzenia.
     */
    private function cleanImageFilename(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $base = basename($path);

        $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        if ($ext === 'jpeg' || $ext === '') {
            $ext = 'jpg';
        }

        // usuń wszystkie powtórzone rozszerzenia obrazów z końca nazwy
        $name = preg_replace('/(\.(jpe?g|png|gif|webp))+$/i', '', $base);
        if ($name === null || $name === '') {
            $name = (string) pathinfo($base, PATHINFO_FILENAME);
        }

        return $name . '.' . $ext;
    }

    private function getImages($product, $item)
    {

        foreach (($item['images'] ?? []) as $image) {
            try {
                if ($this->validateImageUrl($image)) {
                    $product->addMediaFromUrl($image)
                        ->usingFileName($this->cleanImageFilename($image))
                        ->toMediaCollection('images');
                } else {
                    Log::warning('SumpguardSource: pominięto nieprawidłowy URL obrazu', [
                        'product_id'  => $product->id,
                        'external_id' => $product->external_id ?? null,
                        'url'         => $image,
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('SumpguardSource: nie udało się pobrać/przetworzyć obrazu', [
                    'product_id'  => $product->id,
                    'external_id' => $product->external_id ?? null,
                    'url'         => $image,
                    'error'       => $exception->getMessage(),
                ]);
            }

        }
    }

    private function getProducts($json, $locale)
    {
        $json->each(function ($item) use ($locale) {

            $data = [
                'source_id' => $this->source->id,
                'external_id' => $item['id'],
                'category' => $this->vauxhallClear($item['category']) . '/' . $this->vauxhallClear($item['sub_category']),
                'name' => $this->getName($item, $locale),
                'product_code' => $item['product_code'],
                'width' => (float)$item['width'],
                'weight' => (float)$item['weight'],
                'comment' => $item['comment'],
            ];

            $product = $this->products->get($item['id']);
            if ($product) {
                // Ochrona ręcznych tłumaczeń: usuń z payloadu sloty `name->{locale}` które user lub import oznaczyli jako 'manual'/'sheet_import'.
                // Pozostałe locale wciąż dostają fallback z Sumpguard.
                $lockedLocales = TranslationOverride::lockedLocales($product, 'name');
                if (!empty($lockedLocales) && is_array($data['name'])) {
                    foreach ($lockedLocales as $lockedLocale) {
                        unset($data['name'][$lockedLocale]);
                    }
                    if (empty($data['name'])) {
                        // Wszystkie sloty zablokowane → nie ruszaj kolumny `name` w ogóle.
                        unset($data['name']);
                    } else {
                        // Zostaw tylko niezablokowane sloty + zachowaj istniejące zablokowane (merge).
                        $existing = $product->getTranslations('name');
                        foreach ($lockedLocales as $lockedLocale) {
                            if (isset($existing[$lockedLocale])) {
                                $data['name'][$lockedLocale] = $existing[$lockedLocale];
                            }
                        }
                    }
                }

                // Suppress observer — żeby ten programatyczny update NIE oflagował slotów jako 'manual'.
                TranslationOverride::$suppressObserver = true;
                try {
                    $product->update($data);
                } finally {
                    TranslationOverride::$suppressObserver = false;
                }

                // Backfill: pobierz zdjęcia także dla istniejących produktów,
                // które nie mają jeszcze żadnych mediów (np. po nieudanym
                // wcześniejszym przebiegu importu) — bez tego nigdy się nie naprawią.
                if ($product->getMedia('images')->isEmpty()) {
                    $this->getImages($product, $item);
                }

            } else {
                TranslationOverride::$suppressObserver = true;
                try {
                    $product = Product::create(array_merge($data, ['enabled' => false]));
                } finally {
                    TranslationOverride::$suppressObserver = false;
                }
                $this->getImages($product, $item);

                $category = $this->getCategory($item);
                $product->categories()->sync([$category->id, $category->parent_id]);

                // Sklejacz: spróbuj wypełnić wszystkie locale + nazwy per konto Allegro z matrycy fraz.
                // Atrybuty (make, model) ustawione będą zaraz dalej w `attributeValues()->sync`,
                // więc composer wywołujemy PO sync atrybutów (sklejacz potrzebuje make+model).
                $product->attributeValues()->sync($this->getAttributes($item));
                try {
                    app(\App\Services\ProductTranslationComposer::class)->apply($product->fresh(['attributeValues.attribute']));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Composer apply failed for new product', [
                        'product_id' => $product->id,
                        'error'      => $e->getMessage(),
                    ]);
                }

                return; // wczesny return — atrybuty już zsynchronizowane wyżej, nie powtarzaj.
            }

            $product->attributeValues()->sync($this->getAttributes($item));

        });

    }
    private function buildNameTranslations(string $raw): array
    {
        $base = $this->vauxhallClear($raw);

        // Tylko domyślny locale — pozostałe sloty ZOSTAJĄ PUSTE.
        // Wcześniej kopiowaliśmy PL do wszystkich 14 locale, co wciskało polski tekst do slotów DE/FR/etc.
        // Teraz puste sloty wypełni matryca (TranslationPhrase) lub ręczna edycja w PIM.
        $default = app()->getLocale() ?: 'en';
        return [$default => $base];
    }
    private function getCategory(array $item)
    {
        $default = app()->getLocale() ?? 'en';

        // PARENT
        $parentNameDefault = $this->vauxhallClear($item['category'] ?? '');
        $parent = Category::query()->firstOrCreate(
            [
                'parent_id' => $this->root_category->id,
                "name->{$default}" => $parentNameDefault, // szukamy po nazwie w domyślnym języku
            ],
            [
                'parent_id' => $this->root_category->id,
                'name'      => $this->buildNameTranslations($item['category'] ?? ''),
            ]
        );

        // CHILD
        $childNameDefault = $this->vauxhallClear($item['sub_category'] ?? '');
        return Category::query()->firstOrCreate(
            [
                'parent_id' => $parent->id,
                "name->{$default}" => $childNameDefault,
            ],
            [
                'parent_id' => $parent->id,
                'name'      => $this->buildNameTranslations($item['sub_category'] ?? ''),
            ]
        );
    }

    private function getPrices($json)
    {

        $pricelist = Pricelist::firstOrCreate(['slug' => 'sumpguard'], [
            'name' => 'Sump Guard',
            'currency' => 'EUR',
        ]);


        $prices = $json->map(function ($item) use ($pricelist) {
            $product_id = $this->products->get($item['id'])?->id;

            if (!$product_id) {
                return null;
            }

            return [
                'pricelist_id' => $pricelist->id,
                'product_id' => $product_id,
                'price' => (float)$item['eur_alek'],
            ];

        })->filter()->toArray();

        PricelistProduct::upsert($prices, ['pricelist_id', 'product_id'], ['price']);
    }


    private function getJsonData($locale)
    {

        if (isset($this->language_country_codes[$locale])) {
            $locale = $this->language_country_codes[$locale];
        }

//        $storage_path = storage_path("app/sumpguard/{$locale}.json");
//        if (file_exists($storage_path)) {
//            return collect(json_decode(file_get_contents($storage_path), true));
//        }

        $response = Http::sink(storage_path("app/sumpguard/{$locale}.json"))->get("https://pl.sump-guard.co.uk/api/products/json/$locale");
        return $response->collect();
    }

    private function getTranslations(Collection $json, $locale)
    {

        $json->each(function ($item) use ($locale) {
            $this->product_translations[$item['id']]['name'][$locale] = $item['name'];
        });

    }

    private function getAttributes($item)
    {
        $attributes = [];

        $item['make'] = $this->vauxhallClear($item['category']);
        $item['model'] = trim(str_replace($item['make'], '', $item['sub_category']));

        collect($this->source_attributes)->each(function ($source_attribute) use (&$attributes, $item) {

            $attribute = $this->attributes->get(Str::slug($source_attribute));
            $values = $attribute->values->keyBy('slug');

            if (isset($item[$source_attribute])) {

                $source_values = $item[$source_attribute];

                if (!is_array($source_values)) {
                    $source_values = [$source_values];
                }

                foreach ($source_values as $value) {
                    if (!empty($value)) {
                        $value = trim($value);
                        $slug = Str::slug($value);
                        $attributeValue = $values->get($slug);

                        if (!$attributeValue) {

                            $name = $value;
                            // Tylko domyślny locale — patrz uwaga przy buildNameTranslations().
                            $default = app()->getLocale() ?: 'en';
                            $attributeValue = AttributeValue::firstOrCreate([
                                'attribute_id' => $attribute->id,
                                'slug' => $slug
                            ], [
                                'name' => [$default => $name],
                            ]);
                        }

                        $attributes[] = $attributeValue->id;
                    }

                }

            }


        });

        return $attributes;
    }


    private function compare()
    {
        $date = now()->toDateString();
        $date_path = "sumpguard/history/{$date}.json";
        $current_path = 'sumpguard/history/current.json';
        $prev_path = 'sumpguard/history/prev.json';

        $response = $this->getJsonData('pl');

        if (Storage::exists($current_path)) {
            Storage::delete($prev_path);
            Storage::move($current_path, $prev_path);
        } else {
            Storage::put($prev_path, $response);
        }

        Storage::put($current_path, $response);
        Storage::put($date_path, $response);

        $prev = collect(json_decode(Storage::get($prev_path)))->keyBy('id');
        $current = collect(json_decode($response))->keyBy('id');

        $diffs = $current->map(function ($item) use ($prev) {
            $prev_item = $prev->get($item->id);
            if (is_null($prev_item) || $prev_item == $item) return null;

            $item_array = (array)$item;
            $diff = Arr::only($item_array, ['name', 'product_code']);
            foreach ($item_array as $key => $value) {
                $prev_value = $prev_item->{$key};
                if ($prev_value !== $value) {
                    if (is_array($value)) {
                        $diff['diffs'][] = ['key' => $key, 'prev' => implode(',', $prev_value), 'current' => implode(',', $value)];
                    } elseif (!is_array($value)) {
                        $diff['diffs'][] = ['key' => $key, 'prev' => $prev_value, 'current' => $value];
                    }
                }
            }
            return $diff;
        })->filter()->toArray();

        $news = $current->map(function ($item) use ($prev) {
            $prev_item = $prev->get($item->id);
            if (is_null($prev_item)) {

                $item_array = (array)$item;
                return Arr::only($item_array, ['name', 'product_code']);
            }

            return null;

        })->filter()->toArray();

        if (count($diffs) || count($news)) {
            Mail::to(['info@bsplate.eu', 'a.sliwinski@argosolutions.pl'])->send(new SumpguardEmail($diffs, $news));
        }


    }
}
