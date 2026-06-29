<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request;

use Mdev\LaravelPrestashop\Client\Api\Client;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\TaxRule as TaxRuleContract;

class TaxRule extends Client implements TaxRuleContract
{

    /**
     * Get tax rules
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getTaxRules(array $options): Response
    {
        return new Response(
            $this->get(array_merge(['resource' =>'tax_rules'], $options))
        );
    }
}
