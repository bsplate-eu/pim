<?php

namespace App\Sources;

use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Models\Source;
use App\Settings\GeneralSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use XMLReader;

class AlnorSource extends BaseSource implements SourceInterface
{

    private Collection $products;

    private Collection $attributes;

    private string $xml_path;
    private Pricelist $pricelist;
    private array $prices;

    public function __construct(protected Source $source)
    {
        parent::__construct($source);
        $this->xml_path = storage_path("app/alnor/awema.xml");
        $this->locales = app(GeneralSettings::class)->available_locales;
        $this->pricelist = Pricelist::firstOrCreate(['slug' => 'alnor'], [
            'name' => 'Alnor',
            'currency' => 'PLN',
        ]);

    }

    private function setProducts()
    {
        $this->products = Product::select(['id', 'external_id'])->get()->keyBy('external_id');
    }


    public function synchronize()
    {
        $this->getXml();
        $this->getProducts();
    }


    private function saveImages($product, $item)
    {

        foreach ($item['images'] as $image) {
            dump($image);
            try {
                $product->addMediaFromUrl($image)->toMediaCollection('images');
            } catch (\Exception $exception) {
                dump($exception->getMessage());
            }

        }
    }

    private function getImages(XMLReader $reader)
    {
        $images = [];
        while ($reader->read() && $reader->name !== 'imgs') {
            if ($reader->nodeType == XMLReader::ELEMENT) {
                $images[] = $reader->getAttribute('url');
            }
        }
        return $images;
    }

    private function getAttributes(XMLReader $reader)
    {
        $atrybuty = [];
        while ($reader->read() && $reader->name !== 'attrs') {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->name === 'a') {
                $nazwa = $reader->getAttribute('name');
                $reader->read();
                $atrybuty[$nazwa] = $reader->value;
            }
        }
        return $atrybuty;
    }

    private function getProducts()
    {

        $this->setProducts();
        $reader = new XMLReader();
        $reader->open($this->xml_path);

        $product = null;

        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT) {
                switch ($reader->name) {
                    case 'o':
                        if ($product !== null) {
                            $this->saveProduct($product);
                        }
                        $product = [
                            'id' => $reader->getAttribute('id'),
                            'price' => $reader->getAttribute('price'),
                            'enabled' => (int)$reader->getAttribute('stock') > 0,
//                            'stock' => $reader->getAttribute('stock'),
                        ];
                        break;
                    case 'name':
                        $reader->read();
                        $product['name'] = $reader->value;
                        break;
                    case 'cat':
                        $reader->read();
                        $product['category'] = $reader->value;
                        break;
                    case 'imgs':
                        $product['images'] = $this->getImages($reader);
                        break;
                    case 'desc':
                        $reader->read();
                        $product['description'] = $reader->value;
                        break;
                    case 'attrs':
                        $product['attributes'] = $this->getAttributes($reader);
                        break;
                }
            }
        }

        if ($product !== null) {
            $this->saveProduct($product);
        }

        $reader->close();

        $this->savePrices();


    }

    private function skuToInt($sku)
    {
        $sku = preg_replace('/[^a-zA-Z0-9]/', '', $sku);

        $number = '';
        $sku = strtoupper($sku);

        for ($i = 0; $i < strlen($sku); $i++) {
            $char = $sku[$i];
            if (ctype_alpha($char)) {
                $number .= str_pad(ord($char) - 64, 2, '0', STR_PAD_LEFT);
            } else {
                $number .= $char;
            }
        }

        return (int)$number;
    }

    private function savePrices()
    {
        PricelistProduct::upsert($this->prices, ['pricelist_id', 'product_id'], ['price']);
        $this->prices = [];
    }

    private function saveProduct($item)
    {
        if (!isset($item['attributes']['GTIN'])) return;

        $names = [];

        foreach ($this->locales as $locale) {
            $names[$locale] = $item['name'];
        }
        dump($item);

        $data = [
            'source_id' => $this->source->id,
            'external_id' => $item['attributes']['GTIN'],
            'ean' => $item['attributes']['GTIN'],
            'category' => $item['category'],
            'name' => $names,
            'product_code' => $item['id'],
            'enabled' => $item['enabled'],
        ];

        $product = $this->products->get($data['external_id']);
        if ($product) {
            $product->update($data);
        } elseif ($item['enabled']) {
            $product = Product::create($data);
//            $this->saveImages($product, $item);
        }

        if ($product) {

            $this->prices[] = [
                'pricelist_id' => $this->pricelist->id,
                'product_id' => $product->id,
                'price' => (float)$item['price'],
            ];


            if (count($this->prices) >= 1000) {
                $this->savePrices();
            }
        }
    }


    private function getXml()
    {
        return Http::sink($this->xml_path)->get("http://144.91.105.151/integrations/alnor/awema.xml");
    }


}
