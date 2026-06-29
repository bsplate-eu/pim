<?php

namespace Mdev\LaravelPrestashop\Facades;

use Mdev\LaravelPrestashop\Contracts\Prestashop as PrestashopContract;
use Illuminate\Support\Facades\Facade;

class Prestashop extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return PrestashopContract::class;
    }
}
