<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\AdvertentieUitgaveResource;

class EditAdvertentieUitgave extends EditRecord
{
    protected static string $resource = AdvertentieUitgaveResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return AdvertentieUitgaveResource::naarOpslag($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
