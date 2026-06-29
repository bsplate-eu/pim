<?php
namespace Mdev\LaravelPrestashop\Client\Contracts;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApi;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Language as LanguageContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Permission as PermissionContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Product as ProductContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Category as CategoryContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Manufacturer as ManufacturerContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Tax as TaxContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\TaxRule as TaxRuleContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\StockAvailable as StockAvailableContract;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Image as ImageContract;

interface PrestashopClient
{
    /**
     * Languages
     *
     * @return LanguageContract
     */
    public function languages(): LanguageContract;

    /**
     * Permissions
     *
     * @return PermissionContract
     */
    public function permissions(): PermissionContract;

    /**
     * Products
     *
     * @return ProductContract
     */
    public function products(): ProductContract;

    /**
     * Categories
     *
     * @return CategoryContract
     */
    public function categories(): CategoryContract;

    /**
     * Manufacturers
     *
     * @return ManufacturerContract
     */
    public function manufacturers(): ManufacturerContract;

    /**
     * Taxes
     *
     * @return TaxContract
     */
    public function taxes(): TaxContract;

    /**
     * Tax rules
     *
     * @return TaxRuleContract
     */
    public function taxRules(): TaxRuleContract;

    /**
     * Stock availables
     *
     * @return StockAvailableContract
     */
    public function stockAvailables(): StockAvailableContract;

    /**
     * Images
     *
     * @return ImageContract
     */
    public function images(): ImageContract;
}
