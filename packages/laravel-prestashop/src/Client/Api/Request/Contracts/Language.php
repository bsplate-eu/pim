<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request\Contracts;

use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;

interface Language
{
    /**
     * Get languages
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getLanguages(array $options): Response;

    /**
     * Get name language by ID
     *
     * @param int $languageId
     * @return string|null
     * @throws PrestashopApiException
     */
    public function getNameLanguageById($languageId);
}
