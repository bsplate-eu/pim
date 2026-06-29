<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request\Contracts;

use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;

interface Shop
{
    /**
     * Get shops
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getShops(array $options): Response;

    /**
     * Get name by ID
     *
     * @param int $languageId
     * @return string|null
     * @throws PrestashopApiException
     */
    public function getNameById($languageId);
}
