<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request\Contracts;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;

interface Category
{
    /**
     * Get categories
     *
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function getCategories(array $options = []): Response;

    /**
     * Get category schema blank
     *
     * @return Response
     * @throws PrestashopApiException
     */
    public function getCategorySchemaBlank(): Response;

    /**
     * Add category
     *
     * @param string $postXml
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function addCategory(string $postXml, array $options = []): Response;
}
