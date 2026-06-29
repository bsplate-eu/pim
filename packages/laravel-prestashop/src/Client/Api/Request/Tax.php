<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request;

use Mdev\LaravelPrestashop\Client\Api\Client;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Tax as TaxContract;

class Tax extends Client implements TaxContract
{

    /**
     * Get taxes
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getTaxes(array $options): Response
    {
        return new Response(
            $this->get(array_merge(['resource' =>'taxes'], $options))
        );
    }
}
