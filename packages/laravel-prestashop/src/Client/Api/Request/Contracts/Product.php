<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request\Contracts;

use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;

interface Product
{
    /**
     * Get products
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getProducts(array $options): Response;

    /**
     * Delete product
     *
     * @param int $id
     * @param array $options
     * @return bool
     * @throws PrestashopApiException
     */
    public function deleteProduct(int $id, array $options = []):bool;

    /**
     * Get product schema blank
     *
     * @return Response
     * @throws PrestashopApiException
     */
    public function getProductSchemaBlank(): Response;

    /**
     * Add product
     *
     * @param string $postXml
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function addProduct(string $postXml, array $options = []): Response;

    /**
     * Update product
     *
     * @param int $id
     * @param string $putXml
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function updateProduct(int $id, string $putXml, array $options = []): Response;
}
