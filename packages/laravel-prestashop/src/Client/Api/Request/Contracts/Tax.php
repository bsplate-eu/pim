<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request\Contracts;

use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;

interface Tax
{
    /**
     * Get taxes
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getTaxes(array $options): Response;
}
