<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request\Contracts;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;

interface Feature
{
    /**
     * Get categories
     *
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function getFeatures(array $options = []): Response;

    /**
     * Get category schema blank
     *
     * @return Response
     * @throws PrestashopApiException
     */
    public function getFeatureSchemaBlank(): Response;

    /**
     * Add category
     *
     * @param string $postXml
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function addFeature(string $postXml, array $options = []): Response;
}
