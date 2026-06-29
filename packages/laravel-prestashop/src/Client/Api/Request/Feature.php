<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request;

use Mdev\LaravelPrestashop\Client\Api\Client;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Feature as FeatureContract;

class Feature extends Client implements FeatureContract
{
    /**
     * Get categories
     *
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function getFeatures(array $options = []): Response
    {
        return new Response(
            $this->get(array_merge(['resource' => 'product_features'], $options))
        );
    }


    /**
     * Get category schema blank
     *
     * @return Response
     * @throws PrestashopApiException
     */
    public function getFeatureSchemaBlank(): Response
    {
        return new Response(
            $this->getSchema('product_features')
        );
    }

    /**
     * Add category
     *
     * @param string $postXml
     * @param array $options
     * @return Response
     * @throws PrestashopApiException
     */
    public function addFeature(string $postXml, array $options = []): Response
    {
        return new Response(
            $this->add(array_merge(['resource' => 'categories', 'postXml' => $postXml], $options))
        );
    }

    public function addValue(string $postXml, array $options = []): Response
    {
        return new Response(
            $this->add(array_merge(['resource' => 'product_feature_values', 'postXml' => $postXml], $options))
        );
    }
}
