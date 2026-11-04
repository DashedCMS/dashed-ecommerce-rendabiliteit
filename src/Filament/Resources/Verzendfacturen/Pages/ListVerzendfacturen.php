<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\VerzendfactuurResource;

class ListVerzendfacturen extends ListRecords
{
    protected static string $resource = VerzendfactuurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
