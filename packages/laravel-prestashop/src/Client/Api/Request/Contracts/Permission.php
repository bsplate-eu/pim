<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request\Contracts;

use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;

interface Permission
{
    /**
     * Get permissions
     *
     * @param array $data
     * @return Response
     * @throws PrestashopApiException
     */
    public function getPermissions(array $options): Response;
}
