<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven;

use UnitEnum;
use BackedEnum;
use Carbon\Carbon;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Support\Enums\Width;
use Filament\Actions\DeleteAction;
use Illuminate\Auth\Access\Response;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\View;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Dashed\DashedEcommerceRendabiliteit\Models\AdvertentieUitgave;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieImport;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentiePlatformen;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\Pages\EditAdvertentieUitgave;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\Pages\ListAdvertentieUitgaven;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\Pages\CreateAdvertentieUitgave;

/**
 * Advertentie-uitgaven per dag en platform (excl. btw), handmatig of via plakimport.
 * Alleen deze dagregels tellen mee in het rapport; dagen zonder regel schat het rapport
 * met het daggemiddelde uit Tarieven.
 */
class AdvertentieUitgaveResource extends Resource
{
    public const UITLEG = 'Werkelijke advertentie-uitgaven per dag (excl. btw). Dagen zonder regel schat het rapport met het daggemiddelde uit Tarieven.';

    protected static ?string $model = AdvertentieUitgave::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Rendabiliteit';

    protected static ?string $navigationLabel = 'Advertentie-uitgaven';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'rendabiliteit/advertentie-uitgaven';

    protected static ?string $modelLabel = 'advertentie-uitgave';

    protected static ?string $pluralModelLabel = 'advertentie-uitgaven';

    /** Alle acties alleen met view_statistics. */
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        return auth()->check() && auth()->user()->can('view_statistics') ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('datum')
                ->label('Datum')
                ->default(fn () => now()->subDay()->toDateString())
                ->required(),
            TextInput::make('platform')
                ->label('Platform')
                ->datalist(AdvertentiePlatformen::BEKEND)
                ->maxLength(255)
                ->trim()
                ->required()
                // Opgeslagen wordt de genormaliseerde naam ("facebook" → Meta); valideer daar ook op.
                ->mutateStateForValidationUsing(fn (?string $state) => AdvertentiePlatformen::normaliseer($state))
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                        ->where('datum', $get('datum') ? Carbon::parse($get('datum'))->toDateString() : null),
                )
                ->validationMessages([
                    'unique' => 'Voor dit platform staat op deze dag al een bedrag; bewerk dat bedrag.',
                ]),
            TextInput::make('bedrag')
                ->label('Bedrag (excl. btw)')
                ->numeric()
                ->minValue(0)
                ->prefix('€')
                ->required(),
        ]);
    }

    /** Opslaan vanuit het formulier: genormaliseerde platformnaam en bron handmatig. */
    public static function naarOpslag(array $data): array
    {
        $data['platform'] = AdvertentiePlatformen::normaliseer($data['platform'] ?? null);
        $data['bron'] = 'handmatig';

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(self::UITLEG)
            ->defaultSort(fn ($query) => $query->orderByDesc('datum')->orderBy('platform'))
            ->columns([
                TextColumn::make('datum')->label('Datum')->date('d-m-Y')->sortable(),
                TextColumn::make('platform')->label('Platform')->searchable()->sortable(),
                TextColumn::make('bedrag')->label('Bedrag (excl. btw)')
                    ->formatStateUsing(fn ($state) => self::geld((float) $state))
                    ->sortable()
                    ->summarize(
                        Summarizer::make()
                            ->label('Totaal')
                            ->using(fn ($query) => (float) $query->sum('bedrag'))
                            ->formatStateUsing(fn ($state) => self::geld((float) $state)),
                    ),
                TextColumn::make('bron')->label('Bron')->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'handmatig' => 'Handmatig', 'api' => 'API', default => 'Import'
                    })
                    ->color(fn (?string $state) => $state === 'handmatig' ? 'gray' : 'info'),
            ])
            ->filters([
                SelectFilter::make('platform')->label('Platform')
                    ->options(fn () => AdvertentieUitgave::query()->distinct()->orderBy('platform')->pluck('platform', 'platform')->all()),
                SelectFilter::make('maand')->label('Maand')
                    ->options(fn () => self::maandOpties())
                    ->query(function (Builder $query, array $data) {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $maand = Carbon::createFromFormat('!Y-m', $data['value']);

                        return $query->whereBetween('datum', [$maand->toDateString(), $maand->copy()->endOfMonth()->toDateString()]);
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** Kopactie: plakken → Controleren (voorbeeld, niets opgeslagen) → Importeren. */
    public static function importActie(): Action
    {
        return Action::make('importeren')
            ->label('Importeren')
            ->icon('heroicon-o-arrow-up-tray')
            ->modalHeading('Advertentie-uitgaven importeren')
            ->modalDescription('Plak regels "datum;platform;bedrag" (kopregel mag; ook tab of komma als scheidingsteken, datum als JJJJ-MM-DD of DD-MM-JJJJ, bedragen excl. btw). Klik op Controleren; pas bij Importeren wordt er iets opgeslagen.')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Importeren')
            ->schema([
                Textarea::make('tekst')
                    ->label('Regels')
                    ->rows(10)
                    ->placeholder("datum;platform;bedrag\n2026-09-01;Google;14,20\n2026-09-01;Meta;22,75")
                    ->live(onBlur: true)
                    ->hint(fn (Get $get) => self::isOngecontroleerdeWijziging($get) ? 'Tekst gewijzigd — klik opnieuw op Controleren' : null)
                    ->hintColor('warning')
                    ->required(),
                Hidden::make('gecontroleerd'),
                Actions::make([
                    Action::make('controleren')
                        ->label('Controleren')
                        ->color('gray')
                        ->icon('heroicon-o-magnifying-glass')
                        ->action(fn (Get $get, Set $set) => $set('gecontroleerd', (string) $get('tekst'))),
                ])->key('controleActies'),
                View::make('dashed-ecommerce-rendabiliteit::filament.resources.advertentie-uitgaven.import-voorbeeld')
                    ->viewData(fn (Get $get) => ['regels' => app(AdvertentieImport::class)->voorbeeld((string) $get('gecontroleerd'))])
                    // Verdwijnt zodra de tekst na het controleren nog wijzigt, zodat er geen oud voorbeeld blijft staan.
                    ->visible(fn (Get $get) => filled($get('gecontroleerd')) && ! self::isOngecontroleerdeWijziging($get)),
            ])
            ->action(function (array $data, Action $action) {
                if (blank($data['gecontroleerd'] ?? null) || $data['gecontroleerd'] !== $data['tekst']) {
                    Notification::make()
                        ->warning()
                        ->title('Klik eerst op Controleren')
                        ->body('De regels zijn nog niet (of na een wijziging niet opnieuw) gecontroleerd.')
                        ->send();

                    $action->halt();
                }

                $telling = app(AdvertentieImport::class)->importeer($data['tekst']);

                Notification::make()
                    ->success()
                    ->title('Advertentie-uitgaven geïmporteerd')
                    ->body("{$telling['nieuw']} nieuw, {$telling['bijgewerkt']} bijgewerkt, {$telling['gelijk']} ongewijzigd, {$telling['fout']} overgeslagen (fout).")
                    ->send();
            });
    }

    /** Tekst gewijzigd nadat op Controleren is geklikt: het voorbeeld hoort dan niet meer bij de tekst. */
    private static function isOngecontroleerdeWijziging(Get $get): bool
    {
        return filled($get('gecontroleerd')) && $get('gecontroleerd') !== $get('tekst');
    }

    /** @return array<string, string> 'Y-m' → "september 2026", nieuwste eerst */
    private static function maandOpties(): array
    {
        return AdvertentieUitgave::query()
            ->orderByDesc('datum')
            ->pluck('datum')
            ->map(fn ($datum) => Carbon::parse($datum)->format('Y-m'))
            ->unique()
            ->mapWithKeys(fn (string $maand) => [$maand => Carbon::createFromFormat('!Y-m', $maand)->locale('nl')->translatedFormat('F Y')])
            ->all();
    }

    private static function geld(float $bedrag): string
    {
        return '€ '.number_format($bedrag, 2, ',', '.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdvertentieUitgaven::route('/'),
            'create' => CreateAdvertentieUitgave::route('/create'),
            'edit' => EditAdvertentieUitgave::route('/{record}/edit'),
        ];
    }
}
