<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\VasteLasten\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\VasteLasten\VasteLastResource;

class ListVasteLasten extends ListRecords
{
    protected static string $resource = VasteLastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
