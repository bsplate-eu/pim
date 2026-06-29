<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request\Contracts;

use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;

interface Image
{
    /**
     * Add image
     *
     * @param int $idProduct
     * @param string $imagePath
     * @param array $options
     * @return bool
     * @throws PrestashopApiException
     */
    public function addImage(int $idProduct, string $imagePath, array $options = []): bool;
}
