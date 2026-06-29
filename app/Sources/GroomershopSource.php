<?php

namespace App\Sources;

use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class GroomershopSource extends BaseSource implements SourceInterface
{


    private string $base_url = 'https://groomershop.pl';

    private array $categories = [
        'akcesoria',
        'kosmetyki',
        'pielegnacja',
    ];

    private array $json_attributes = [
        'name',
        'short_description',
        'description',
        'attributes',
        'sku',
    ];

    private Client $client;

    private array $prices = [];


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function synchronize()
    {
        $this->initClient();
        shuffle($this->categories);

        foreach ($this->categories as $category) {
            $this->scrapCategoryProducts($category);
        }
    }


    private function initClient()
    {


        $cookieJar = new CookieJar();
        $headers = [
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/107.0.0.0 Safari/537.36',
            'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'accept-encoding' => 'gzip, deflate, br',
        ];

        $this->client = new Client(['base_url' => $this->base_url, 'verify' => false, 'http_errors' => false, 'cookies' => $cookieJar, 'headers' => $headers]);
    }


    public function scrapCategoryProducts($category, $page = 1)
    {
        dump("Category: $category, page: $page");
        $url = "{$this->base_url}/{$category}";
        $response = $this->client->get($url, [
            'query' => [
                'p' => $page,
                'product_list_limit' => 60
            ]
        ]);

        $crawler = new Crawler($response->getBody()->getContents());
        $links = $crawler->filter('a.cs-product-tile__thumbnail-link')->each(function ($item) {
            return ['link' => $item->attr('href')];
        });

        $this->pool($links);
        $this->upsertPrices();
        $next_page = $crawler->filter('a.cs-pagination__action--next-page')->count();

        if ($next_page) {
            $this->scrapCategoryProducts($category, ++$page);
        }

    }

    public function pool($items)
    {
        $requestGenerator = function ($items) {
            foreach ($items as $item) {
                yield $item => function () use ($item) {
                    return $this->client->getAsync($item['link']);
                };
            }
        };

        $pool = new Pool($this->client, $requestGenerator($items), [
            'concurrency' => 10,
            'fulfilled' => function (Response $response, $key) {
                dump("Product: {$key['link']}");
                $crawler = new Crawler($response->getBody()->getContents());
                $this->getProduct($crawler);
            },
            'rejected' => function (\Exception $reason, $key) {
                dump("Product: {$key['link']}, Error {$reason->getMessage()}");
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();
    }

    private function getProduct($crawler)
    {
        $category = $this->parseCategories($crawler);
        $json = $this->getJson($crawler);
        $ldjson = $this->getLdJson($crawler);
        $images = $ldjson?->image ?? [];
        $url = $crawler->filter('link[rel="canonical"]')->first()->attr('href') ?? null;

        if ($json) {
            $this->parseVariants($json, $images, $category, $url);
        } elseif ($ldjson) {
            $this->parseProduct($ldjson, $crawler, $images, $category, $url);
        }
    }

    private function parseProduct($json, $crawler, $images, $category, $url)
    {

        try {

            if (is_array($json->offers)) {
                $offer = $json->offers[0];
            } else {
                $offer = $json->offers;
            }

            $price = $offer?->price;
            $in_stock = $offer?->availability == 'InStock';
            $id = $crawler->filter('.action.towishlist.cs-buybox__addto-link')->first()->attr('data-product-id') ?? null;
            $short_description = $crawler->filter('meta[name="description"]')->first()->attr('content') ?? null;
            $attributes = $crawler->filter('.cs-product-details-main__column--right .cs-product-details-main__content')->first()->html();
            $description = $crawler->filter('.product.attribute.description .value')->first()->html();

            $data = [
                'id' => $id,
                'category' => $category,
                'price' => $price,
                'name' => $json->name,
                'short_description' => $short_description,
                'description' => $description,
                'attributes' => $attributes,
                'sku' => $json->sku,
                'in_stock' => $in_stock,
                'quantity' => null,
                'images' => $images,
            ];

            $this->storeProduct($data);
//        $this->putProductXml($data);

        } catch (\Exception $e) {
            Log::error("Product: {$url}, Error: {$e->getMessage()}");
        }


    }

    private function getJson($crawler)
    {
        $json = null;
        $crawler->filter('script[type="text/x-magento-init"]')->each(function ($item) use (&$json) {
            $script = $item->text();
            if (str_contains($script, 'swatch-options')) {
                $json = json_decode($script);
                $json = $json->{'[data-role=swatch-options]'}->{'Magento_Swatches/js/swatch-renderer'}->jsonConfig;
            }
        });

        return $json;
    }

    private function getLdJson($crawler)
    {
        $json = null;
        $crawler->filter('script[type="application/ld+json"]')->each(function ($item) use (&$json) {
            $script = $item->text();
            if (str_contains($script, '"@type": "Product"')) {
                $json = json_decode($script);
            }
        });

        return $json;
    }

    private function parseVariants(object $json, $images, $category, $url)
    {
        $variants = [];

        foreach ($json->optionPrices as $key => $optionPrice) {
            $variants[$key]['id'] = $key;
            $variants[$key]['category'] = $category;
            $variants[$key]['price'] = $optionPrice->finalPrice->amount;
        }

//        foreach ($json->attributes as $id => $attribute) {
//            foreach ($attribute->options as $option){
//                foreach ($option->products as $product)
//                $variants[$product]['options'][$attribute->label] = $option->label;
//            }
//
//        }

        foreach ($json->product_information as $key => $product_information) {
            foreach ($this->json_attributes as $attribute) {
                $variants[$key][$attribute] = $this->getJsonValue($product_information, $variants['default'] ?? [], $attribute);
            }
            $variants[$key]['images'] = $images;
//            if(isset($variants[$key]['attributes'])){
//                $variants[$key]['short_description'] = strip_tags($this->escapeHtml($variants[$key]['short_description']));
//                $variants[$key]['description'] = $this->escapeHtml($variants[$key]['attributes'] . $variants[$key]['description']);
//                $variants[$key]['attributes'] = $this->parseAttributes($variants[$key]['attributes']);
//            }

        }

        unset($variants['default']);

        foreach ($variants as $variant) {
            $this->storeProduct($variant);
        }

    }


    private function storeProduct(array $data)
    {

        $product = Product::firstOrCreate([
            'source_id' => $this->source->id,
            'external_id' => $data['id'],
        ], [
            'product_code' => $data['sku'],
            'category' => $data['category'],
            'name' => ['pl' => $this->escapeHtml($data['name'])],
            'info_1' => ['pl' => strip_tags($this->escapeHtml($data['attributes'] . $data['description']))],
            'info_2' => ['pl' => strip_tags($this->escapeHtml($data['short_description']))],
        ]);

        $this->prices[$product->id] = $data['price'];

//        if (!$product->hasMedia('images')) {
//            $this->getImages($product, $data['images']);
//        }
    }

    private function upsertPrices()
    {

        $pricelist = Pricelist::firstOrCreate(['slug' => 'groomershop'], [
            'name' => 'Groomershop',
            'currency' => 'PLN',
        ]);


        $prices = collect($this->prices)->map(function ($price, $key) use ($pricelist) {
            return [
                'pricelist_id' => $pricelist->id,
                'product_id' => $key,
                'price' => (float)$price,
            ];
        })->filter()->toArray();

        PricelistProduct::upsert($prices, ['pricelist_id', 'product_id'], ['price']);

        $this->prices = [];
    }


    private function getImages($product, $images)
    {
        foreach ($images as $image) {
            dump($image);
            try {
                $product->addMediaFromUrl($image)->toMediaCollection('images');
            } catch (\Exception $exception) {
                dump($exception->getMessage());
            }

        }
    }

    private function escapeHtml($html)
    {
        $html = htmlspecialchars_decode($html, ENT_QUOTES);
        $html = preg_replace('/\s\s+/', ' ', $html);

        return trim($html);
    }

    private function getJsonValue(object $product_information, array $default, string $attribute)
    {
        if (isset($product_information->{$attribute}->value)) {
            return $product_information->{$attribute}->value;
        }

        if (isset($default[$attribute])) {
            return $default[$attribute];
        }

        return null;
    }

    private function parseAttributes($html)
    {
        $attributes = [];
        $crawler = new Crawler($html);

        $crawler->filter('table tr')->each(function ($item) use (&$attributes) {
            $key = $item->filter('th')->first()->text();
            $value = $item->filter('td')->first()->text();

            if (!empty($key) && !empty($value)) {
                $attributes[] = "$key:$value";
            }
        });

        return implode('|', $attributes);
    }

    private function parseCategories($crawler)
    {


        $categories = [];
        $crawler->filter('.cs-breadcrumbs a.cs-breadcrumbs__link')->each(function ($item) use (&$categories) {
            $name = $item->text();
            if (!str_contains($name, 'Strona główna')) {
                $categories[] = $name;
            }
        });

        return implode('/', $categories);
    }

}
