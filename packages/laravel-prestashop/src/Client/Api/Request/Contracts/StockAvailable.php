<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request\Contracts;

use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;

interface StockAvailable
{
    /**
     * Get stock availables
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getStockAvailables(array $options): Response;

    /**
     * Update stock availables
     *
     * @param int $id
     * @param string $putXml
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function updateStockAvailables(int $id, string $putXml, array $options = []): Response;
}
