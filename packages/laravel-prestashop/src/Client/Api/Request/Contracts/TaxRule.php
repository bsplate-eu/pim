<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request\Contracts;

use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;

interface TaxRule
{
    /**
     * Get tax rules
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getTaxRules(array $options): Response;
}
