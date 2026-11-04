<?php

namespace Dashed\DashedEcommerceRendabiliteit;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DashedEcommerceRendabiliteitServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ecommerce-rendabiliteit';

    public function packageRegistered(): void
    {
        $this->app->singleton(Rendabiliteit::class);
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile();
    }
}
