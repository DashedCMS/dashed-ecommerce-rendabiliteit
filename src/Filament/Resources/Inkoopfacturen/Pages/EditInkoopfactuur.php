<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Dashed\DashedEcommerceRendabiliteit\Services\Inkoop\InkoopfactuurKoppelaar;
use Dashed\DashedEcommerceRendabiliteit\Services\Inkoop\InkoopfactuurVerwerker;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen\InkoopfactuurResource;

class EditInkoopfactuur extends EditRecord
{
    protected static string $resource = InkoopfactuurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('koppelen')
                ->label('Koppelen')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->action(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $gekoppeld = app(InkoopfactuurKoppelaar::class)->koppel($this->record);
                    $this->herlaad();

                    Notification::make()->success()->title("{$gekoppeld} regels gekoppeld aan een product")->send();
                }),
            Action::make('toepassen')
                ->label('Toepassen')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalDescription('Elk gekoppeld product krijgt de prijs per stuk excl. btw als inkoopprijs. Een regel die al is toegepast, wordt niet opnieuw toegepast.')
                ->action(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $bijgewerkt = app(InkoopfactuurVerwerker::class)->pasToe($this->record);
                    $this->herlaad();

                    Notification::make()->success()->title("Inkoopprijs van {$bijgewerkt} producten bijgewerkt")->send();
                }),
            InkoopfactuurResource::downloadActie(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(InkoopfactuurKoppelaar::class)->onthoudHandmatige($this->record);
    }

    private function herlaad(): void
    {
        $this->record->refresh();
        $this->fillForm();
    }
}
