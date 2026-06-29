<?php

namespace App\Services;

use App\Mail\SumpguardEmail;
use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Settings\GeneralSettings;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;

class SumpguardService
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


    public function import()
    {
        $locales = app(GeneralSettings::class)->available_locales;
        $locale = array_shift($locales);

        $json = $this->getJsonData($locale);
        $this->getProducts($json, $locale);

        $this->products = Product::select(['id', 'external_id'])->get()->keyBy('external_id');

        $this->getPrices($json);
        $this->getTranslations($json, $locale);

        foreach ($locales as $locale) {

            $json = $this->getJsonData($locale);
            $this->getTranslations($json, $locale);
        }

        $this->translate();


        Product::with('media')->whereDoesntHave('media')->get()->each(function ($product) {
            foreach ($product->images as $image) {
                dump($image);
                try {
                    $product->addMediaFromUrl($image)->toMediaCollection('images');
                } catch (\Exception $exception) {
                    dump($exception->getMessage());
                }

            }
        });

        $this->compare();
    }

    private function translate()
    {
        collect($this->product_translations)
            ->each(function ($item, $key) {
                $product = $this->products->get($key);
                if ($product) {
                    $product->update($item);
                }
            });

//        $tranlsations = collect($this->product_translations)
//            ->map(fn($item, $key) => [
//                'id' => $this->products->get($key)?->id,
//                'name' => json_encode($item['name']),
//                'protection' => json_encode($item['protection']),
//            ])
//            ->filter(fn($item) => $item['id'])
//            ->values()
//            ->toArray();
//        Product::upsert($tranlsations, 'id', ['name', 'protection']);
    }

    private function vauxhallClear($string)
    {
        return str_replace('Vauxhall', 'Opel', $string);
    }

    private function getProducts($json, $locale)
    {
        $products = $json->map(function ($item) use ($locale) {
            $item['external_id'] = $item['id'];
            $item['name'] = json_encode([$locale => $this->vauxhallClear(htmlspecialchars_decode($item['name']))]);
            $item['secondary_name'] = $this->vauxhallClear($item['secondary_name']);
            $item['price'] = $item['eur_alek'];
            $item['category'] = $this->vauxhallClear($item['category']);
            $item['sub_category'] = $this->vauxhallClear($item['sub_category']);
            $item['width'] = (float)$item['width'];
            $item['weight'] = (float)$item['weight'];
            $item['protection'] = json_encode([$locale => array_map(fn($i) => htmlspecialchars_decode($i), $item['protection'])]);
            $item['images'] = json_encode($item['images']);
            unset($item['id'], $item['eur_alek'], $item['parent_id']);
            return $item;
        })->toArray();

        Product::upsert($products, 'external_id', [
            'category',
            'sub_category',
            'name',
            'secondary_name',
            'product_code',
            'price',
            'year_start',
            'year_stop',
            'width',
            'weight',
            'oil',
            'engine',
            'gearbox',
            'related_products',
            'comment',
            'protection',
            'images'
        ]);
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


    private function setPriceRanges()
    {

        $this->price_ranges = [
            ['min' => 0, 'max' => 350, 'multiplier' => 2.2, 'addon' => 0],
            ['min' => 351, 'max' => 475, 'multiplier' => 2, 'addon' => 0.05],
            ['min' => 476, 'max' => PHP_FLOAT_MAX, 'multiplier' => 1.95, 'addon' => 0.05],
        ];
    }

    private function getPrice($price)
    {
        foreach ($this->price_ranges as $range) {
            if ($range['min'] <= $price && $range['max'] >= $price) {
                $base = $price * $range['multiplier'];
                return $base + $base * $range['addon'];
            }
        }

        return ceil($price);
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
            $this->product_translations[$item['id']]['protection'][$locale] = $item['protection'];
        });

    }

    public function compare()
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
            Mail::to(['info@bsplate.eu'])->send(new SumpguardEmail($diffs, $news));
        }


    }
}
