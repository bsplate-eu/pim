<?php

namespace Mdev\LaravelPrestashop\Client;

use Mdev\LaravelPrestashop\Client\Api\Config;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApi;
use Mdev\LaravelPrestashop\Client\Api\Request\Category;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Feature as FeatureContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Category as CategoryContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Image as ImageContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Language as LanguageContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Manufacturer as ManufacturerContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Product as ProductContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\StockAvailable as StockAvailableContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Tax as TaxContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\TaxRule as TaxRuleContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Feature;
use Mdev\LaravelPrestashop\Client\Api\Request\Image;
use Mdev\LaravelPrestashop\Client\Api\Request\Language;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Permission as PermissionContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Manufacturer;
use Mdev\LaravelPrestashop\Client\Api\Request\Permission;
use Mdev\LaravelPrestashop\Client\Api\Request\Product;
use Mdev\LaravelPrestashop\Client\Api\Request\StockAvailable;
use Mdev\LaravelPrestashop\Client\Api\Request\Tax;
use Mdev\LaravelPrestashop\Client\Api\Request\TaxRule;
use Mdev\LaravelPrestashop\Client\Contracts\PrestashopClient as PrestashopClientContract;

class PrestashopClient implements PrestashopClientContract
{
    /** @var Config $config */
    private $config;

    public function __construct(array $parameters)
    {
        $this->config = new Config($parameters);
    }

    /**
     * Languages
     *
     * @return LanguageContract
     */
    public function languages(): LanguageContract
    {
        return new Language($this->config);
    }

    /**
     * Permissions
     *
     * @return PermissionContract
     */
    public function permissions(): PermissionContract
    {
        return new Permission($this->config);
    }
    /**
     * Products
     *
     * @return ProductContract
     */
    public function products(): ProductContract{
        return new Product($this->config);
    }

    /**
     * Categories
     *
     * @return CategoryContract
     */
    public function categories(): CategoryContract{
        return new Category($this->config);
    }

    /**
     * Categories
     *
     * @return CategoryContract
     */
    public function features(): FeatureContract{
        return new Feature($this->config);
    }

    /**
     * Manufacturers
     *
     * @return ManufacturerContract
     */
    public function manufacturers(): ManufacturerContract{
        return new Manufacturer($this->config);
    }

    /**
     * Taxes
     *
     * @return TaxContract
     */
    public function taxes(): TaxContract{
        return new Tax($this->config);
    }

    /**
     * Tax rules
     *
     * @return TaxRuleContract
     */
    public function taxRules(): TaxRuleContract{
        return new TaxRule($this->config);
    }

    /**
     * Stock availables
     *
     * @return StockAvailableContract
     */
    public function stockAvailables(): StockAvailableContract{
        return new StockAvailable($this->config);
    }

    /**
     * Images
     *
     * @return ImageContract
     */
    public function images(): ImageContract{
        return new Image($this->config);
    }
}
