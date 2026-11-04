<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Dashed\DashedEcommerceRendabiliteit\Models\Inkoopfactuur;
use Dashed\DashedEcommerceRendabiliteit\Services\Inkoop\InkoopfactuurKoppelaar;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen\InkoopfactuurResource;

class CreateInkoopfactuur extends CreateRecord
{
    protected static string $resource = InkoopfactuurResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['bron'] = ($data['bron'] ?? null) === Inkoopfactuur::BRON_AI ? Inkoopfactuur::BRON_AI : Inkoopfactuur::BRON_HANDMATIG;

        return $data;
    }

    /** Na opslaan meteen koppelen; wat niet gevonden wordt kies je op de bewerkpagina. */
    protected function afterCreate(): void
    {
        $koppelaar = app(InkoopfactuurKoppelaar::class);
        $koppelaar->onthoudHandmatige($this->record);
        $gekoppeld = $koppelaar->koppel($this->record);

        $zonder = $this->record->regels()->where('soort', 'product')->whereNull('product_id')->count();
        $melding = Notification::make()->title("{$gekoppeld} regels gekoppeld aan een product");
        ($zonder
            ? $melding->warning()->body("{$zonder} regels zonder product: kies het product zelf en klik daarna op Toepassen.")
            : $melding->success()->body('Controleer de regels en klik op Toepassen.'))->send();
    }

    protected function getRedirectUrl(): string
    {
        return InkoopfactuurResource::getUrl('edit', ['record' => $this->record]);
    }
}
