<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\VerzendfactuurResource;

class EditVerzendfactuur extends EditRecord
{
    protected static string $resource = VerzendfactuurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VerzendfactuurResource::downloadActie(),
            DeleteAction::make(),
        ];
    }
}
