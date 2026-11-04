<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\KostentariefResource;

class EditKostentarief extends EditRecord
{
    protected static string $resource = KostentariefResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return KostentariefResource::normaliseerSleutel($data);
    }
}
