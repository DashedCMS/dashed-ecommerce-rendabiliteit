<?php

namespace Dashed\DashedEcommerceRendabiliteit;

use Livewire\Livewire;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\Package;
use Dashed\DashedEcommerceCore\Models\OrderProduct;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommerceRendabiliteit\Commands\HaalAdvertenties;
use Dashed\DashedEcommerceRendabiliteit\Commands\VulOrderregelKostprijzen;
use Dashed\DashedEcommerceRendabiliteit\Observers\OrderregelKostprijsObserver;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\RoasChart;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\WeekTabel;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\PlatformTabel;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\AdvertentieKpis;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\UitgavenOmzetChart;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\OmzetPerKanaalChart;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\UitgavenVerdelingChart;

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

        // De widgets van de Advertenties-pagina staan niet in het panel (anders komen ze op het
        // hoofddashboard), dus Livewire kent ze niet uit zichzelf. Zonder deze registratie vindt
        // een update-verzoek de component niet en antwoordt Livewire met een 419.
        foreach ([
            AdvertentieKpis::class,
            UitgavenOmzetChart::class,
            RoasChart::class,
            UitgavenVerdelingChart::class,
            OmzetPerKanaalChart::class,
            PlatformTabel::class,
            WeekTabel::class,
        ] as $widget) {
            Livewire::component($widget);
        }

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
