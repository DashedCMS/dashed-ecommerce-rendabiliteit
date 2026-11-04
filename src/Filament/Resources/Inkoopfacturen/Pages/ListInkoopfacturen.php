<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen\InkoopfactuurResource;

class ListInkoopfacturen extends ListRecords
{
    protected static string $resource = InkoopfactuurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
