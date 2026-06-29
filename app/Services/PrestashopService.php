<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\IntegrationSource;
use App\Models\PricelistProduct;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mdev\LaravelPrestashop\Client\PrestashopClient;


class PrestashopService
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
    private Collection $taxes;

    private int $root_category_id;
    private Collection $prestashop_features;
    private IntegrationSource $integrationSource;

    public function __construct(private Integration $integration)
    {
        $this->integration->load('integrationSources.template');
        app()->setLocale($this->integration->integrationSources->first()->template->locale);
        $this->prestashop = new PrestashopClient(['url' => $this->integration->url, 'api_key' => $this->integration->key]);
        $this->connector = new ConnectorService($this->integration);
//        $this->connector->updateConnector();
        $this->categories = [];
        $this->root_category_id = 2;

    }


    private function getIntegrationCategories()
    {
        return Category::descendantsOf($this->integration->category_id)->toTree();
    }

    private function syncExternalIds()
    {
        IntegrationProduct::where('integration_id', $this->integration->id)
            ->whereNotNull('external_id')
            ->get()
            ->chunk(100)
            ->each(function ($chunk) {

                $existing_ids = $this->existsTargetIds($chunk->pluck('external_id')->toArray());
                IntegrationProduct::where('integration_id', $this->integration->id)
                    ->whereIn('id', $chunk->pluck('id')->toArray())
                    ->whereNotIn('external_id', $existing_ids)
                    ->update(['external_id' => null, 'synced_at' => null]);

            });


    }

    private function syncCategoriesLevel($parent_id, $integration_categories, $prestashop_categories)
    {
        foreach ($integration_categories as $ic) {
            if (!empty($ic->name)) {
                try {

                    $pc = collect($prestashop_categories)->where('name', $ic->name)->first();
                    if (!$pc) {
                        dump("Adding category: " . $ic->name . " with parent ID: " . $parent_id);
                        $pc = $this->connector->addCategory($parent_id, $ic->name);
                        $pc['id_category'] = $pc['category_id'];
                    } else {
                        dump("Category already exists: " . $ic->name . " with parent ID: " . $parent_id);
                    }
                    $this->categories[$ic->id] = $pc['id_category'];

                    if ($pc &&$ic->children->count() > 0) {
                        $this->syncCategoriesLevel($pc['id_category'], $ic->children, $pc['children'] ?? []);
                    }

                }catch (\Exception $exception){
                    dump('Exception: '. $exception->getMessage());
                }

            }else{
                dump("Category empty name: " . $ic->id . " with parent ID: " . $parent_id);
            }


        }
    }

    public function syncCategories()
    {

        try {

            $integration_categories = $this->getIntegrationCategories();

            $prestashop_categories = $this->connector->getCategoryTree();

            if ($prestashop_categories[0]['id_category'] == $this->root_category_id) {
                $prestashop_categories = collect($prestashop_categories[0]['children'] ?? []);

                $this->syncCategoriesLevel($this->root_category_id, $integration_categories, $prestashop_categories);
            } else {
                dump("Root category not found");
            }

        } catch (\Exception $exception) {
            dump($exception->getMessage());
            Log::error($exception->getMessage(), $exception->getTrace());
        }

    }


    public function syncFeatures()
    {
//       brand/model bez subkategorii??
//        $created = false;
//        $this->prestashop_features = $this->connector->getFeatures();
//
//        $products = Product::select('category')->distinct()->get();
//
//        $features = [
//            'Brand' => $products->pluck('category')->unique()->toArray(),
//            'Model' => '?',
//            'Year' => range(1988, date('Y')),
//        ];
//
//        foreach ($features as $name => $values) {
//            $feature_id = $this->prestashop_features->firstWhere('feature_name', $name)['id_feature'] ?? null;
//            if (!$feature_id) {
//                $feature_id = $this->connector->addFeature($name);
//                $created = true;
//            }
//            foreach ($values as $value) {
//                $feature_value = $this->prestashop_features->where('feature_name', $name)->where('feature_value', $value)->first();
//                if (!$feature_value) {
//                    $this->connector->addFeatureValue($feature_id, $value);
//                    $created = true;
//                }
//            }
//        }
//
//        if ($created) {
//            $this->prestashop_features = $this->connector->getFeatures();
//        }

    }

    private function addProductFeatures(Product $product, int $external_id)
    {

        //       brand/model bez subkategorii??
//        $features_data = [];
//
//        $features = [
//            'Brand' => [$product->category],
//            'Model' => [$product->sub_category],
//            'Year' => range($product->year_start, $product->year_stop),
//        ];
//
//        foreach ($features as $name => $values) {
//
//            foreach ($values as $value) {
//                $feature_value = $this->prestashop_features->where('feature_name', $name)->where('feature_value', $value)->first();
//                if ($feature_value) {
//                    $features_data[] = $feature_value;
//                }
//            }
//
//        }
//
//        $this->connector->addProductFeatures($external_id, $features_data);

    }


    private function prepareName(string $name)
    {
        $name = htmlspecialchars_decode($name);
        $name = preg_replace('/[<>;=#{}]/', '-', $name);
        $name = preg_replace('/[\'\"]/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return Str::limit($name, 128, '');
    }

    private function getLanguages()
    {
        if (!$this->languages) {
            $this->languages = [];
            $languages = $this->prestashop->languages()->getLanguages(['display' => '[id,name]'])->getArray('//languages/language');
            foreach ($languages as $language) {
                $this->languages[] = [
                    'id' => (int)$language['id'],
                ];
            }
        }
        return $this->languages;
    }

    public function syncProducts()
    {

        $this->syncExternalIds();

        $this->products = [];
        $this->schemaBlank = $this->prestashop->products()->getProductSchemaBlank()->xml();

        $this->getTaxes();

        $this->integration->integrationSources->each(function ($integrationSource) {
            $this->integrationSource = $integrationSource;
            app()->setLocale($integrationSource->template->locale);
            $this->prices = PricelistProduct::where('pricelist_id', $integrationSource->pricelist->id)->get()->keyBy('product_id');
            IntegrationProduct::with('product.media', 'product.attributeValues', 'product.categories')
                ->where('integration_id', $this->integration->id)
                ->where('integration_source_id', $integrationSource->id)
                ->get()
                ->each(function ($ip) {

                    $product = $ip->getOverridedProduct();
                    if ($product->enabled) {
                        if ($ip->external_id) {
                            $this->updateProduct($product, $ip->external_id);
//                    $this->addProductFeatures($product, $ip->external_id);
                        } else {
                            $external_id = $this->createProduct($product);
                            $ip->external_id = $external_id;
                            $ip->save();
                        }
                    }
                });

        });


        $this->updateProducts();
    }


    private function createProduct(Product $product)
    {
        $price = $this->getPrice($product);
        $has_price = $price > 1;

        $name = $this->prepareName($this->integrationSource->template->getRenderedTitle($product));
        $description = $this->integrationSource->template->getRenderedDescription($product);

        $meta_title = $this->integrationSource->template->getRenderedMetaTitle($product);
        $meta_description = $this->integrationSource->template->getRenderedMetaDescription($product);

        $description_short = $this->integrationSource->template->getRenderedShortDescription($product);
        $targetCategoryIds = $this->getTargetCategoryIds($product);

        $xml = $this->schemaBlank;
        $resources = $xml->children()->children();

        unset($resources->id);
        unset($resources->position_in_category);
        unset($resources->manufacturer_name);
        unset($resources->id_default_combination);
        unset($resources->associations->categories);
        unset($resources->associations->product_features);


        foreach ($this->getLanguages() as $key => $language) {
            $resources->name->language[$key][0] = $name;
            $resources->name->language[$key][0]['id'] = $language['id'];
            $resources->description->language[$key][0] = $description;
            $resources->description->language[$key][0]['id'] = $language['id'];
            $resources->description_short->language[$key][0] = $description_short;
            $resources->description_short->language[$key][0]['id'] = $language['id'];
            $resources->meta_title->language[$key][0] = $meta_title;
            $resources->meta_title->language[$key][0]['id'] = $language['id'];
            $resources->meta_description->language[$key][0] = $meta_description;
            $resources->meta_description->language[$key][0]['id'] = $language['id'];
            $resources->link_rewrite->language[$key][0] = Str::slug($name);
            $resources->link_rewrite->language[$key][0]['id'] = $language['id'];
        }

        if (!empty($product->ean)) {
            $resources->ean13 = $product->ean;
        }

        $resources->id_tax_rules_group = $this->getIntegrationSourceTax();

        $resources->reference = $product->product_code;
        $resources->supplier_reference = $product->external_id;
        $resources->id_shop = 1;
        $resources->minimal_quantity = 1;
        $resources->available_for_order = (int)$has_price;
        $resources->show_price = (int)$has_price;
        $resources->id_category_default = $targetCategoryIds->last();
        $resources->price = $price;
        $resources->active = (int)$product->enabled;
        $resources->state = 1;

        $categoriesXml = $resources->associations->addChild('categories');
        foreach ($targetCategoryIds as $targetCategoryId) {
            $category = $categoriesXml->addChild('category');
            $category->addChild('id', $targetCategoryId);
        }

        $xmlString = $xml->asXML();

        $psProduct = $this->prestashop->products()->addProduct($xmlString)->xml()->product;
        $psProductId = (int)$psProduct->id;

        foreach ($product->getMedia('images') as $media) {
            if ($media->size < Product::MAX_MEDIA_SIZE && in_array($media->mime_type, Product::ALLOWED_MEDIA_TYPES)) {
                $this->prestashop->images()->addImage($psProductId, $media->getPath());
            }
        }

//            $this->addProductFeatures($product, $psProductId);

        return $psProductId;


    }


    private function updateProduct(Product $product, $external_id)
    {

        $price = $this->getPrice($product);
        $has_price = $price > 1;

        $name = $this->prepareName($this->integrationSource->template->getRenderedTitle($product));
        $description = $this->integrationSource->template->getRenderedDescription($product);
        $description_short = $this->integrationSource->template->getRenderedShortDescription($product);

        $data = [
            'id' => $product->id,
            'product_id' => $external_id,
            'name' => $name,
            'description' => $description,
            'description_short' => $description_short,
            'link_rewrite' => Str::slug($name),
            'stock' => 0,
            'available_for_order' => (int)$has_price,
            'show_price' => (int)$has_price,
        ];

        if ($has_price) {
            $data['netto_price'] = $price;
            $data['stock'] = 100;
        }


        $data['id_tax_rules_group'] = $this->getIntegrationSourceTax();


        $this->products[] = $data;
        if (count($this->products) > 100) {
            $this->updateProducts();
        }


    }

    private function existsTargetIds(array $external_ids): array
    {
        $return = [];
        $targetIdsParameter = collect($external_ids)->join('|');
        $products = $this->prestashop->products()->getProducts([
            'filter[id]' => '[' . $targetIdsParameter . ']',
            'display' => '[id]'
        ])->getArray('//products/product');
        foreach ($products as $product) {
            $return[] = (int)$product['id'];
        }
        return $return;
    }

    private function getPrice($product)
    {
        $price = $this->prices->get($product->id)?->price ?? 0;
        return $price * $this->integrationSource->multiplier / (1 + $this->integrationSource->vat / 100);
    }

    private function getTargetCategoryIds($product)
    {
        $categories = [$this->root_category_id];

        foreach ($product->categories as $item) {
            if (isset($this->categories[$item->id])) {
                $categories[] = (int)$this->categories[$item->id];
            }
        }

        return collect($categories);
    }


    private function updateProducts()
    {

        if (count($this->products)) {
            $this->connector->updateProducts($this->products);
            IntegrationProduct::where('integration_id', $this->integration->id)
                ->whereIn('product_id', array_column($this->products, 'id'))
                ->update(['synced_at' => now()]);
        }

        $this->products = [];
    }

    private function getTaxes()
    {
        $this->taxes = $this->connector->getTaxes();
    }

    private function getIntegrationSourceTax()
    {

        $id_tax_rules_group = null;
        if (!empty($this->integrationSource->tax)){
            $id_tax_rules_group = $this->taxes->get($this->integrationSource->tax)['id_tax_rules_group'] ?? null;
        }

        return $id_tax_rules_group;

    }


}
