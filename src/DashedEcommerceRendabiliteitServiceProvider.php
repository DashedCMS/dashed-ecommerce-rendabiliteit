<?php

namespace Dashed\DashedEcommerceRendabiliteit;

use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\Package;
use Dashed\DashedEcommerceCore\Models\OrderProduct;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommerceRendabiliteit\Commands\HaalAdvertenties;
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

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Orderregels die pas later een inkoopprijs kregen (bijv. via een inkoopfactuur).
            $schedule->command(VulOrderregelKostprijzen::class)->dailyAt('03:50')->withoutOverlapping();
            // Doet niets zolang er geen koppeling aan staat.
            $schedule->command(HaalAdvertenties::class)->dailyAt('05:20')->withoutOverlapping();
        });
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasRoutes(['api', 'web'])
            ->hasCommands([
                VulOrderregelKostprijzen::class,
                HaalAdvertenties::class,
            ]);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
