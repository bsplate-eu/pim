<?php

namespace App\Sources;

use App\Models\Product;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class BezetSource extends BaseSource implements SourceInterface
{

    private string $base_url = 'https://bezet.pl';
    private array $products = [];
    private Client $client;


    public function synchronize()
    {
        ini_set('memory_limit', '512M');
        $this->client = new Client(['base_url' => $this->base_url, 'verify' => false, 'http_errors' => false, 'allow_redirects' => true]);
        $this->getCategories();

    }

    private function getCategories()
    {
        $html = Http::get($this->base_url . '/kategoria-produktu/deco/')->body();
        $crawler = new Crawler($html);
        $crawler->filter('nav.elementor-nav-menu--main.elementor-nav-menu--layout-vertical a.elementor-item')
            ->each(function ($node) {
                $this->parseCategory($node->attr('href'));
            });
    }

    private function parseCategory($url, $page = 1)
    {
        $suffix = "";
        if($page > 1) {
            $suffix = "page/$page/";
        }
        dump($url. $suffix);
        $html = Http::get($url. $suffix)->body();
        $crawler = new Crawler($html);
        $crawler->filter('h3.product_title a')
            ->each(function ($node) {
                $this->products[] = $node->attr('href');
            });

        $this->getProducts();

        if ($crawler->filter('a.page-numbers.next')->count()) {
            $this->parseCategory($url, $page + 1);
        }

    }


    private function getProducts()
    {

        $requestGenerator = function () {
            foreach ($this->products as $item) {
                yield $item => function () use ($item) {
                    return $this->client->getAsync($item);
                };
            }
        };

        $pool = new Pool($this->client, $requestGenerator(), [
            'concurrency' => 20,
            'fulfilled' => function (Response $response) {
                $this->getProduct($response);
            },
            'rejected' => function (\Exception $reason, $key) {
                dump("Product: {$key}, Error {$reason->getMessage()}");
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();
        $this->products = [];
    }

    private function getProduct(Response $response)
    {
        $crawler = new Crawler($response->getBody()->getContents());

        $sku = $this->getSku($crawler);
        $category = $this->getCategoryPath($crawler);

        $external_id = $this->getProductId($crawler);
        $name = $crawler->filter('h1.product_title')->text();

        if (is_null($sku) || is_null($external_id)) {
            dump($response->getHeaders());
            return;
        }

        $info_1 = $crawler->filter('.elementor-widget-woocommerce-product-content p');
        if($info_1->count() > 0) {
            $info_1 = $info_1->last()->text();
        }else {
            $info_1 = null;
        }
        $product = Product::firstOrCreate([
            'source_id' => $this->source->id,
            'external_id' => $external_id,
        ], [
            'product_code' => $sku,
            'category' => implode('/', $category),
            'name' => ['pl' => $name],
            'info_1' => ['pl' => $info_1],
        ]);

        if($product->getTranslation('info_1', 'pl') == null) {
            $product->setTranslation('info_1', 'pl', $info_1);
            $product->save();
        }

        if (!$product->hasMedia('images')) {
            $this->getImages($product, $crawler);
        }
    }

    private function getSku($crawler): ?string
    {
        $sku = null;
        $metaTag = $crawler->filter('meta[name="description"]');
        if ($metaTag->count() > 0) {
            $content = $metaTag->attr('content');
            if (preg_match('/Symbol:\s*(\S+)/', $content, $matches)) {
                $sku = $matches[1];
            } else {
                dump($content);
            }
        }

        return $sku;
    }

    private function getProductId($crawler)
    {
        $alternateLink = $crawler->filter('link[rel="alternate"][type="application/json"]');

        if ($alternateLink->count() > 0) {
            $href = $alternateLink->attr('href');
            if (preg_match('/\/product\/(\d+)/', $href, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function getCategoryPath($crawler): array
    {
        $breadcrumb = $crawler->filter('nav.woocommerce-breadcrumb');

        if ($breadcrumb->count() > 0) {

            $items = $breadcrumb->filter('a')->each(function (Crawler $node) {
                return preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', trim($node->text())); // Oczyść tekst
            });

            array_shift($items);

            return $items;
        }

        return [];
    }


    private function getImages($product, $crawler)
    {
        $images = $crawler->filter('img[data-large_image]');

        $highQualityImages = [];

        foreach ($images as $image) {
            $imageCrawler = new Crawler($image);
            $imageUrl = $imageCrawler->attr('data-large_image');
            if ($imageUrl) {
                $highQualityImages[] = $imageUrl;
            }
        }

        foreach ($highQualityImages as $image) {
            dump($image);
            try {
                $product->addMediaFromUrl($image)->toMediaCollection('images');
            } catch (\Exception $exception) {
                dump($exception->getMessage());
            }

        }
    }
}
