<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\Pages;

use Filament\Resources\Pages\CreateRecord;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\AdvertentieUitgaveResource;

class CreateAdvertentieUitgave extends CreateRecord
{
    protected static string $resource = AdvertentieUitgaveResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return AdvertentieUitgaveResource::naarOpslag($data);
    }
}
