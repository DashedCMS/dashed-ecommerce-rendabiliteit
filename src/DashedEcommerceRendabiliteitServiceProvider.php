<?php

namespace Dashed\DashedEcommerceRendabiliteit;

use Spatie\LaravelPackageTools\Package;
use Dashed\DashedEcommerceCore\Models\OrderProduct;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommerceRendabiliteit\Commands\VulOrderregelKostprijzen;
use Dashed\DashedEcommerceRendabiliteit\Observers\OrderregelKostprijsObserver;

class DashedEcommerceRendabiliteitServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ecommerce-rendabiliteit';

    public function packageRegistered(): void
    {
        $this->app->singleton(Rendabiliteit::class);
    }

    public function bootingPackage(): void
    {
        // Legt bij elke nieuwe orderregel de kostprijs van dat moment vast.
        OrderProduct::observe(OrderregelKostprijsObserver::class);
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('api')
            ->hasCommands([
                VulOrderregelKostprijzen::class,
            ]);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
