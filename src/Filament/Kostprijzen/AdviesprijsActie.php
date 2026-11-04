<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Kostprijzen;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Infolists\Components\TextEntry;
use Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen\AdviesprijsDoorvoerder;

/**
 * "Adviesprijs doorvoeren": toont eerst oud → nieuw en voert pas na
 * bevestigen door. Gedeeld door de Marges-pagina (bulk) en eventuele eigen pagina's van de shop.
 */
class AdviesprijsActie
{
    /** @param Closure(mixed $record): Collection $producten */
    public static function voorProducten(Closure $producten, string $naam = 'adviesprijsDoorvoeren'): Action
    {
        return self::configureer(Action::make($naam), fn ($record) => $producten($record));
    }

    public static function voorBulk(): BulkAction
    {
        return self::configureer(BulkAction::make('adviesprijsDoorvoeren'), fn ($records) => collect($records));
    }

    /**
     * Het voorstel wordt bij het openen (en bij het omzetten van "Alleen
     * verhogen") één keer berekend en als JSON in het verborgen veld
     * `voorstel` gezet. De tabel in de modal toont dat veld en bevestigen
     * zet precies die prijzen, zodat er nooit iets anders wordt doorgevoerd
     * dan getoond.
     *
     * @template T of Action
     *
     * @param  T  $actie
     * @param  Closure  $producten  Wordt door de actie zelf geëvalueerd, zodat
     *                              $record (rij-actie) of $records (bulk) wordt ingevuld.
     * @return T
     */
    private static function configureer(Action $actie, Closure $producten): Action
    {
        return $actie
            ->label('Adviesprijs doorvoeren')
            ->icon('heroicon-o-arrow-trending-up')
            ->modalHeading('Adviesprijs doorvoeren')
            ->modalSubmitActionLabel('Prijzen aanpassen')
            ->schema(function ($action) use ($producten) {
                $lijst = $action->evaluate($producten);

                return [
                    Toggle::make('alleen_verhogen')
                        ->label('Alleen verhogen')
                        ->default(true)
                        ->live()
                        ->afterStateUpdated(fn ($state, $set) => $set('voorstel', self::voorstelJson($lijst, (bool) $state))),
                    Hidden::make('voorstel')
                        ->default(fn () => self::voorstelJson($lijst, true)),
                    TextEntry::make('voorstel_tabel')
                        ->label('Voorstel')
                        ->state(fn ($get) => self::tabel(self::leesVoorstel($get('voorstel')))),
                ];
            })
            ->action(function (array $data, $action) use ($producten) {
                // Alleen producten uit de eigen selectie; het verborgen veld komt van de browser.
                $toegestaan = $action->evaluate($producten)->pluck('id')->map(fn ($id) => (int) $id)->flip();
                $prijzen = collect(self::leesVoorstel($data['voorstel'] ?? null)['regels'])
                    ->filter(fn (array $regel) => $toegestaan->has($regel['id']))
                    ->mapWithKeys(fn (array $regel) => [$regel['id'] => $regel['nieuw']])
                    ->all();

                $resultaat = app(AdviesprijsDoorvoerder::class)->voerDoorVolgens($prijzen);

                Notification::make()->title("{$resultaat->gelukt} prijzen aangepast")->success()->send();

                // Alleen echte fouten; verwijderde producten en de van-prijsregel zijn geen fout.
                if ($resultaat->mislukt > 0) {
                    Notification::make()
                        ->title("{$resultaat->mislukt} prijzen konden niet worden aangepast; probeer het opnieuw")
                        ->warning()
                        ->send();
                }
            });
    }

    /** Eén berekening voor voorstel én overgeslagen-telling, als JSON voor het verborgen veld. */
    private static function voorstelJson(Collection $lijst, bool $alleenVerhogen): string
    {
        $uitkomst = app(AdviesprijsDoorvoerder::class)->voorstelMetOverslag($lijst, $alleenVerhogen);

        return json_encode([
            'regels' => $uitkomst['voorstel']->map(fn (array $regel) => [
                'id' => (int) $regel['product']->id,
                'naam' => (string) $regel['product']->name,
                'oud' => $regel['oud'],
                'nieuw' => $regel['nieuw'],
            ])->all(),
            'overgeslagen' => $uitkomst['overgeslagen'],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{regels: array<int, array{id: int, naam: string, oud: float, nieuw: float}>, overgeslagen: int} */
    private static function leesVoorstel(?string $json): array
    {
        $data = json_decode((string) $json, true);
        $regels = [];

        foreach (is_array($data['regels'] ?? null) ? $data['regels'] : [] as $regel) {
            if (is_array($regel) && is_numeric($regel['id'] ?? null) && is_numeric($regel['nieuw'] ?? null) && (float) $regel['nieuw'] > 0) {
                $regels[] = [
                    'id' => (int) $regel['id'],
                    'naam' => (string) ($regel['naam'] ?? ''),
                    'oud' => (float) ($regel['oud'] ?? 0),
                    'nieuw' => (float) $regel['nieuw'],
                ];
            }
        }

        return ['regels' => $regels, 'overgeslagen' => (int) ($data['overgeslagen'] ?? 0)];
    }

    /** @param array{regels: array<int, array{id: int, naam: string, oud: float, nieuw: float}>, overgeslagen: int} $voorstel */
    private static function tabel(array $voorstel): HtmlString
    {
        $html = $voorstel['regels'] === []
            ? '<em>Geen prijzen om aan te passen.</em>'
            : self::tabelHtml($voorstel['regels']);

        if ($voorstel['overgeslagen'] > 0) {
            $html .= sprintf(
                '<p>%d producten overgeslagen: de adviesprijs komt boven hun van-prijs (new_price). Pas die met de hand aan.</p>',
                $voorstel['overgeslagen'],
            );
        }

        return new HtmlString($html);
    }

    /** @param array<int, array{id: int, naam: string, oud: float, nieuw: float}> $regels */
    private static function tabelHtml(array $regels): string
    {
        $rijen = collect($regels)->map(fn (array $regel) => sprintf(
            '<tr><td>%s</td><td style="text-align:right">€ %s</td><td style="text-align:right">€ %s</td></tr>',
            e($regel['naam']),
            number_format($regel['oud'], 2, ',', '.'),
            number_format($regel['nieuw'], 2, ',', '.'),
        ))->implode('');

        return '<table style="width:100%"><thead><tr><th style="text-align:left">Product</th><th style="text-align:right">Nu</th><th style="text-align:right">Nieuw</th></tr></thead><tbody>'.$rijen.'</tbody></table>';
    }
}
