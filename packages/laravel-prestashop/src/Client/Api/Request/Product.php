<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request;

use Mdev\LaravelPrestashop\Client\Api\Client;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Product as ProductContract;

class Product extends Client implements ProductContract
{

    /**
     * Get products
     *
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function getProducts(array $options = []): Response
    {
        return new Response(
            $this->get(array_merge(['resource' =>'products'], $options))
        );
    }

    /**
     * Delete product
     *
     * @param int $id
     * @param array $options
     * @return bool
     * @throws PrestashopApiException
     */
    public function deleteProduct(int $id, array $options = []):bool
    {
        dump('delete ' . $id);
        return $this->delete(array_merge(['resource' =>'products', 'id' =>$id, 'limit'  => '1'], $options));
    }

    /**
     * Get product schema blank
     *
     * @return Response
     * @throws PrestashopApiException
     */
    public function getProductSchemaBlank(): Response{
        return new Response(
            $this->getSchema('products')
        );
    }

    /**
     * Add product
     *
     * @param string $postXml
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function addProduct(string $postXml, array $options = []): Response{
        return new Response(
            $this->add(array_merge(['resource' =>'products', 'postXml' =>$postXml], $options))
        );
    }

    /**
     * Update product
     *
     * @param int $id
     * @param string $putXml
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function updateProduct(int $id, string $putXml, array $options = []): Response{
        return new Response(
            $this->edit(array_merge(['resource' =>'products', 'id' => $id, 'putXml' =>$putXml], $options))
        );
    }
}
