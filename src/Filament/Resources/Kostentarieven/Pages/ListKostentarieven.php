<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\KostentariefResource;

class ListKostentarieven extends ListRecords
{
    protected static string $resource = KostentariefResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
