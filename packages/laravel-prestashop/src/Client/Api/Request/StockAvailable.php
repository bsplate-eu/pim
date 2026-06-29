<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request;

use Mdev\LaravelPrestashop\Client\Api\Client;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\StockAvailable as StockAvailableContract;

class StockAvailable extends Client implements StockAvailableContract
{
    /**
     * Get stock availables
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getStockAvailables(array $options): Response{
        return new Response(
            $this->get(array_merge(['resource' =>'stock_availables'], $options))
        );
    }

    /**
     * Update stock availables
     *
     * @param int $id
     * @param string $putXml
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function updateStockAvailables(int $id, string $putXml, array $options = []): Response{
        return new Response(
            $this->edit(array_merge(['resource' =>'stock_availables', 'id' => $id, 'putXml' =>$putXml], $options))
        );
    }
}
