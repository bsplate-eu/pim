<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\PricelistProduct;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Mdev\LaravelPrestashop\Client\PrestashopClient;


class PrestashopServiceCopy
{

    private PrestashopClient $prestashop;
    private array $languages = [];
    private \SimpleXMLElement $schemaBlank;
    /**
     * @var array|mixed
     */
    private array $categories;
    private array $products;
    private ConnectorService $connector;
    private $prices;
    private array $taxes = [];

    public function __construct(private Integration $integration)
    {
        $integration->load('template');
        $this->prestashop = new PrestashopClient(['url' => $this->integration->url, 'api_key' => $this->integration->key]);
        $this->connector = new ConnectorService($this->integration);
    }


    private function getIntegrationCategories()
    {
        return IntegrationProduct::with('product')
            ->where('integration_id', $this->integration->id)
            ->get()
            ->map(fn($model) => $model->getOverridedProduct()->only(['category', 'sub_category']))
            ->unique(fn($item) => $item['category'] . $item['sub_category']);
    }

    public function syncCategories()
    {

        $integration_categories = $this->getIntegrationCategories();
        $prestashop_categories = $this->connector->getCategories();

        $root = $prestashop_categories->where('id_category', 2)->first();

        $this->categories = [];

        foreach ($integration_categories->unique('category') as $item) {
            $pc = $prestashop_categories->where('name', $item['category'])->first();

            if (!$pc) {
                $pc = $this->connector->addCategory($root['id_category'], $item['category']);
                $pc_id = (int)$pc['category_id'];
            }else{
                $pc_id = (int)$pc['id_category'];
            }


            foreach ($integration_categories->where('category', $item['category']) as $subitem) {
                $psc = $prestashop_categories->where('name', $subitem['sub_category'])->first();

                if (!$psc) {
                    $psc = $this->connector->addCategory($pc_id, $subitem['sub_category']);
                    $psc_id = (int)$psc['category_id'];
                }else{
                    $psc_id = (int)$psc['id_category'];
                }

                $this->categories[$subitem['category'] . '/' . $subitem['sub_category']] = $psc_id;

            }
        }
    }

    public function syncProducts()
    {
        $this->schemaBlank = $this->prestashop->products()->getProductSchemaBlank()->xml();

        $this->getTaxes();

        $this->prices = PricelistProduct::where('pricelist_id', $this->integration->pricelist_id)->get()->keyBy('product_id');
        IntegrationProduct::with('product')
            ->where('integration_id', $this->integration->id)
            ->get()
            ->each(function ($ip) {

                $product = $ip->getOverridedProduct();

                if ($ip->external_id) {
                    $this->updateProduct($product, $ip->external_id);
                } else {
                    $external_id = $this->createProduct($product);
                    $ip->external_id = $external_id;
                    $ip->save();
                }

            });

        $this->updateProducts();
    }


    private function createProduct(Product $product)
    {
        $price = $this->prices->get($product->id)->price ?? 0;

        $name = $this->integration->template->getRenderedTitle($product);

        if($price === 0) return;

        $description = $this->integration->template->getRenderedDescription($product);

        $xml = $this->schemaBlank;
        $resources = $xml->children()->children();
        unset($resources->id);
        unset($resources->position_in_category);
        unset($resources->manufacturer_name);
        unset($resources->id_default_combination);
        unset($resources->associations->categories);
        unset($resources->associations->product_features);


        // Ustawienia pola 'name', 'description', 'link_rewrite'
        $resources->name->language[0][0] = $name;
        $resources->name->language[0][0]['id'] = 1;
        $resources->description->language[0][0] = $description;
        $resources->description->language[0][0]['id'] = 1;
        $resources->link_rewrite->language[0][0] = Str::slug($name);
        $resources->link_rewrite->language[0][0]['id'] = 1;

        $resources->id_tax_rules_group = $this->taxes[$this->integration->tax];

        // Dodanie referencji, kategorii i innych pól
        if ($product->external_id) {
            $resources->reference = $product->external_id;
        }
        $category_id = $this->categories[$product->category . '/' . $product->sub_category];
        $resources->id_shop = 1;
        $resources->minimal_quantity = 1;
        $resources->available_for_order = 1;
        $resources->show_price = 1;
        $resources->id_category_default = $category_id;
        $resources->price = round($price * $this->integration->multiplier / (1 + ($this->integration->vat / 100)), 5);
        $resources->active = 1;
        $resources->state = 1;

        $xmlString = $xml->asXML();

        try {

            $psProduct = $this->prestashop->products()->addProduct($xmlString)->xml()->product;

        }catch (\Exception $e) {
            dd($e->getMessage(), $name);
        }


        return (int)$psProduct->id;
    }


    private function updateProduct(Product $product, $external_id)
    {

        $price = $this->prices->get($product->id)->price ?? 0;

        if($price === 0) return;

        $name = $this->integration->template->getRenderedTitle($product);
        $description = $this->integration->template->getRenderedDescription($product);

        $data = [
            'product_id' => $external_id,
            'name' => $name,
            'description' => $description,
            'link_rewrite' => Str::slug($product->name),
            'netto_price' => round($price * $this->integration->multiplier / (1 + $this->integration->vat / 100), 5),
            'id_tax_rules_group' => $this->taxes[$this->integration->tax] ?? 1,
            'stock' => 100,
        ];

        $this->products[] = $data;
        if (count($this->products) > 100) {
            $this->updateProducts();
        }
    }


    private function updateProducts()
    {

        $this->connector->updateProducts($this->products);
        $this->products = [];
    }

    private function getTaxes()
    {
        $this->taxes = [];
        $taxes = $this->prestashop->taxes()->getTaxes(['display'=>'[id,rate]'])->xml()->xpath('//taxes/tax');
        foreach ($taxes as $tax){
            $id = (int) $tax->id;
            $rate = (int) $tax->rate;
            $taxRules = $this->prestashop->taxRules()->getTaxRules(['display'=>'[id_tax_rules_group]', 'filter[id_tax]'=>'['.$id.']', 'limit'=>'1'])->xml()->xpath('//tax_rules/tax_rule');
            foreach ($taxRules as $taxRule){
                $this->taxes[$rate] = (int) $taxRule->id_tax_rules_group;
            }
        }
    }

}
