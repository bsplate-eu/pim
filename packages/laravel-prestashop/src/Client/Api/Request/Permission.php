<?php

namespace Mdev\LaravelPrestashop\Client\Api\Request;

use Mdev\LaravelPrestashop\Client\Api\Client;
use Mdev\LaravelPrestashop\Client\Api\PrestashopApiException;
use Mdev\LaravelPrestashop\Client\Api\Response\Response;
use Mdev\LaravelPrestashop\Client\Api\Request\Contracts\Permission as PermissionContract;

class Permission extends Client implements PermissionContract
{

    /**
     * Get permissions
     *
     * @param array $data
     * @return Response
     * @throws BaselinkerApiException
     */
    public function getPermissions(array $options = []): Response
    {
        return new Response(
            $this->get(array_merge(['resource' =>''], $options))
        );
    }
}
