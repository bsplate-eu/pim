<?php

namespace Mdev\LaravelPrestashop;

use Mdev\LaravelPrestashop\Facades\Prestashop;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelPrestashopServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-prestashop')
            ->hasConfigFile();
    }

    public function registeringPackage()
    {
        $this->app->bind(Prestashop::class, function () {
            return new Prestashop;
        });
    }
}
