<?php

namespace Dashed\DashedEcommerceRendabiliteit;

use Filament\Panel;
use Filament\Contracts\Plugin;
use Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Kostprijzen\MargesPage;
use Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit\AdvertentiesPage;
use Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit\RendabiliteitPage;
use Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit\AdvertentieKoppelingenPage;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\VasteLasten\VasteLastResource;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\KostentariefResource;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen\InkoopfactuurResource;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\VerzendfactuurResource;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\AdvertentieUitgaveResource;

class DashedEcommerceRendabiliteitPlugin implements Plugin
{
    public function getId(): string
    {
        return 'dashed-ecommerce-rendabiliteit';
    }

    public function register(Panel $panel): void
    {
        // De widgets van de Advertenties-pagina worden bewust niet geregistreerd:
        // ze horen alleen op die pagina, niet op het hoofddashboard.
        $panel
            ->pages([
                RendabiliteitPage::class,
                AdvertentiesPage::class,
                AdvertentieKoppelingenPage::class,
                MargesPage::class,
            ])
            ->resources([
                AdvertentieUitgaveResource::class,
                KostentariefResource::class,
                VasteLastResource::class,
                VerzendfactuurResource::class,
                ...(config('dashed-ecommerce-rendabiliteit.inkoopfacturen', true) ? [InkoopfactuurResource::class] : []),
            ]);
    }

    public function boot(Panel $panel): void
    {
    }
}
