<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\Pages;

use Filament\Resources\Pages\CreateRecord;
use Dashed\DashedEcommerceRendabiliteit\Models\Verzendfactuur;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\VerzendfactuurResource;

class CreateVerzendfactuur extends CreateRecord
{
    protected static string $resource = VerzendfactuurResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['bron'] = ($data['bron'] ?? null) === Verzendfactuur::BRON_AI ? Verzendfactuur::BRON_AI : Verzendfactuur::BRON_HANDMATIG;

        return $data;
    }
}
