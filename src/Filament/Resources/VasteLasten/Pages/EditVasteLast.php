<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\VasteLasten\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\VasteLasten\VasteLastResource;

class EditVasteLast extends EditRecord
{
    protected static string $resource = VasteLastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
