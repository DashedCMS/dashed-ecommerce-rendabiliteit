<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\AdvertentieUitgaveResource;

class ListAdvertentieUitgaven extends ListRecords
{
    protected static string $resource = AdvertentieUitgaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AdvertentieUitgaveResource::importActie(),
            CreateAction::make(),
        ];
    }
}
