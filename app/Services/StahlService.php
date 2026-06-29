<?php

namespace App\Services;


use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class StahlService
{
    use Dispatchable;

    private Client $client;
    private $base_url = 'https://www.stahl-unterfahrschutz.eu/';
    private array $products = [];
    private array $links = [];
    /**
     * @var false|resource
     */
    private $output;


    private function initClient()
    {
        $this->client = new Client(['base_url' => $this->base_url, 'verify' => false, 'http_errors' => false, 'allow_redirects' => false]);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function import()
    {

        $this->initClient();
        $response = $this->client->get($this->base_url);
        $html = $response->getBody()->getContents();
        $crawler = new Crawler($html);
        $crawler->filter('.product_c a.brand')->each(function ($node) {
            $this->getCategory($node->attr('href'));
        });

        $this->saveData();

    }

    private function saveData ()
    {
        $pricelist = Pricelist::firstOrCreate(['slug' => 'stahl-unterfahrschutz'], [
            'name' => 'Stahl Unterfahrschutz',
            'currency' => 'EUR',
        ]);

        $this->pool(array_unique($this->links));

        $prices = Product::select(['id','product_code'])->get()->map(function ($item) use ($pricelist) {
            $product = $this->products[$item->product_code] ?? null;

            if (!$product) {
                return null;
            }

            return [
                'pricelist_id' => $pricelist->id,
                'product_id' => $item->id,
                'price' => (float)$product['price'],
            ];
        })->filter()->toArray();

        PricelistProduct::upsert($prices, ['pricelist_id', 'product_id'], ['price']);

    }

    private function getCategory($url)
    {
        $response = $this->client->get($url);
        $html = $response->getBody()->getContents();
        $crawler = new Crawler($html);
        $crawler->filter('.product_c .box_1 a')->each(function ($node) {
            $this->links[] = $node->attr('href');
        });
    }

    private function getProduct($response)
    {
        $html = $response->getBody()->getContents();
        $crawler = new Crawler($html);

        $sku = $crawler->filter('.desc_blk .cod-prod p:last-of-type');
        $sku = $sku->count() ? $sku->first()->text() : null;
        $sku = Str::of($sku)->explode(' ')->last();

        $price = $crawler->filter('.desc_blk .price [itemprop="price"]');
        $price = $price->count() ? $price->first()->text() : null;

        $old_price = $crawler->filter('.desc_blk .price .discount');
        $old_price = $old_price->count() ? $old_price->first()->text() : null;
        $old_price = Str::of($old_price)->explode(' ')->first();

        $this->products[$sku] = [
            'old_price' => $old_price,
            'price' => $price,
        ];

    }

    public function pool($items)
    {
        $requestGenerator = function ($items) {
            foreach ($items as $item) {
                yield $item => function () use ($item) {
                    return $this->client->getAsync($item);
                };
            }
        };

        $pool = new Pool($this->client, $requestGenerator($items), [
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
    }

}
