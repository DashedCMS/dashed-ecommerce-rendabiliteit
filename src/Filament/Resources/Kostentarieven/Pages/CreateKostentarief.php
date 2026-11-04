<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\Pages;

use Filament\Resources\Pages\CreateRecord;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\KostentariefResource;

class CreateKostentarief extends CreateRecord
{
    protected static string $resource = KostentariefResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return KostentariefResource::normaliseerSleutel($data);
    }
}
