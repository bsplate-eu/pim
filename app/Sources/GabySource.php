<?php

namespace App\Sources;

use App\Models\Category;
use App\Models\Product;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class GabySource extends BaseSource implements SourceInterface
{


    private string $base_url = 'https://gaby.com.pl';

    private array $category_slugs = [
        'freshwater',
        'sea',
        'tropic',
        'aquarium',
    ];

    private array $languages = [
        'pl',
        'en',
        'fr',
        'de',
    ];

    private string $default_language = 'en';

    private Client $client;
    private array $categories;


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function synchronize()
    {
        $this->initClient();
        $this->getCategories();

        Product::where('source_id', $this->source->id)->where('external_id', 'NOT LIKE', '%GP-%')->delete();

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


    private function getCategories()
    {

        $root_category_name = [];
        foreach ($this->languages as $language) {
            $root_category_name[$language] = 'Gaby';
        }
        $root_category = Category::firstOrCreate(['external_id' => 'gaby.com.pl'], ['name' => $root_category_name]);

        foreach ($this->languages as $language) {

            $url_lang = $language === $this->default_language ? '' : $language;
            $url = "{$this->base_url}/{$url_lang}";
            $response = $this->client->get($url);

            $crawler = new Crawler($response->getBody()->getContents());
            $crawler->filter('.contacts a')->each(function ($item, $index) use (&$categories, $language) {
                $slug = str_replace('.html', '', basename($item->attr('href')));
                if (in_array($slug, $this->category_slugs)) {
                    $categories[$index]['name'][$language] = $item->text();
                    $categories[$index]['external_id'] = $slug;
                }
            });
        }

        foreach ($categories as $c) {
            $this->categories[] = Category::firstOrCreate(['parent_id' => $root_category->id, 'external_id' => $c['external_id']], ['name' => $c['name']]);
        }
    }


    public function scrapCategoryProducts($category)
    {
        dump("Category: $category->name");

        $products = [];

        foreach ($this->languages as $language) {

            $url_lang = $language === $this->default_language ? '' : $language;
            $url = "{$this->base_url}/{$url_lang}/{$category->external_id}.html";

            $response = $this->client->get($url);

            $crawler = new Crawler($response->getBody()->getContents());


            $crawler->filter('.content .block .info .el')->each(function ($item, $index) use ($crawler, $language, &$products) {
                $name = $item->filter('a.link')->text();
                $images = $item->filter('a')->each(function ($item) {
                    $href = $item->attr('href');
                    if (str_contains($href, '.jpg') || str_contains($href, '.jpeg') || str_contains($href, '.png')) {
                        return $this->base_url . ltrim($item->attr('href'), '.');
                    }
                    return null;
                });

                $short_description = $item->filter('h4')->outerHtml();
                $item->filter('p')->each(function ($item) use (&$short_description) {
                    $short_description .= $item->outerHtml();
                });


                $modal_id = $item->filter('a.popup-with-text')->first()->attr('href');
                if (!Str::of($modal_id)->startsWith('#')) {
                    $modal_id = '#' . $modal_id;
                }

                $description = $crawler->filter($modal_id);
                if ($description->count()) {
                    $description = preg_replace('/\s+/', ' ', $description->first()->html());
                } else {
                    $description = '';
                }

                preg_match_all('/\bGP-\d+\b/', $item->text(), $matches);
                $external_ids = Arr::flatten($matches);

                foreach ($external_ids as $external_id) {
                    $products[$external_id]['description'][$language] = $description;
                    $products[$external_id]['short_description'][$language] = $short_description;
                    $products[$external_id]['name'][$language] = $name;
                    $products[$external_id]['images'] = array_filter($images);
                    $products[$external_id]['external_id'] = $external_id;
                }

            });
        }


        foreach ($products as $data) {
            $this->storeProduct($data, $category);
        }
    }


    private function storeProduct(array $data, Category $category)
    {

        $product = Product::updateOrCreate([
            'source_id' => $this->source->id,
            'external_id' => $data['external_id'],
        ], [
            'product_code' => $data['external_id'],
            'category' => $category->name,
            'name' => $data['name'],
            'info_1' => $data['description'],
            'info_2' => $data['short_description'],
            'enabled' => true,
        ]);

        if (!$product->hasMedia('images')) {
            $this->getImages($product, $data['images']);
        }

        $product->categories()->sync([$category->id]);
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

}
