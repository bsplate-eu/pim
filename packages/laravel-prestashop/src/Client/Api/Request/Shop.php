<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request;

use AwemaPL\BaseJS\Exceptions\PublicException;
use Mdev\LaravelPrestashop\Client\Api\Client;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Shop as ShopContract;

class Shop extends Client implements ShopContract
{

    /**
     * Get shops
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getShops(array $options = []): Response
    {
        return new Response(
            $this->get(array_merge(['resource' =>'shops'], $options))
        );
    }

    /**
     * Get name by ID
     *
     * @param int $languageId
     * @return string
     * @throws PrestashopApiException
     */
    public function getNameById($languageId){
        $response = $this->getLanguages(['display' =>'[name]', 'filter[id]' =>"[$languageId]"]);
        $shops = $response->getArray('//shops/shop');
        if (!sizeof($shops)){
            throw new PrestashopApiException('Shop not found in PrestaShop.', PrestashopApiException::ERROR_REQUEST_API_PRESTASHOP, 400, null, null, null, false);
        }
        return $languages[0]['name'];
    }


}
