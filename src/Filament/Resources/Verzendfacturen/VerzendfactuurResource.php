<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen;

use UnitEnum;
use BackedEnum;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Actions\DeleteAction;
use Illuminate\Auth\Access\Response;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Unique;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Filters\SelectFilter;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Dashed\DashedEcommerceRendabiliteit\Support\Vervoerders;
use Dashed\DashedEcommerceRendabiliteit\Models\Verzendfactuur;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\VerzendfactuurLezer;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\VerzendfactuurCorrectie;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\Pages\EditVerzendfactuur;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\Pages\ListVerzendfacturen;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\Pages\CreateVerzendfactuur;

/**
 * Verzendfacturen van Veloyd en MyParcel. Elke factuur corrigeert de geschatte
 * verzendkosten in het rendabiliteitsrapport (VerzendfactuurCorrectie). De PDF
 * staat op de private `local`-disk en kan met Claude worden uitgelezen.
 */
class VerzendfactuurResource extends Resource
{
    public const UITLEG = 'Facturen van de vervoerders (excl. btw). Het rapport vergelijkt het factuurbedrag met de geschatte verzendkosten van alle labels in de factuurperiode (Schatting) en rekent de labels in die periode met die factor; voor latere maanden geldt het gemiddelde van de laatste drie facturen.';

    public const DISK = 'local';

    public const MAP = 'verzendfacturen';

    protected static ?string $model = Verzendfactuur::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Rendabiliteit';

    protected static ?string $navigationLabel = 'Verzendfacturen';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'rendabiliteit/verzendfacturen';

    protected static ?string $modelLabel = 'verzendfactuur';

    protected static ?string $pluralModelLabel = 'verzendfacturen';

    /** Alle acties alleen met view_statistics. */
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        return auth()->check() && auth()->user()->can('view_statistics') ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('PDF')
                ->description('Upload de factuur en laat Claude de velden invullen; controleer ze daarna en pas aan waar nodig.')
                ->columnSpanFull()
                ->schema([
                    FileUpload::make('bestand')
                        ->label('Factuur (PDF)')
                        ->disk(self::DISK)
                        ->directory(self::MAP)
                        ->visibility('private')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(10240)
                        ->preserveFilenames(false)
                        ->downloadable(false)
                        ->openable(false),
                    Actions::make([
                        Action::make('uitlezen')
                            ->label('Uitlezen met Claude')
                            ->icon('heroicon-o-sparkles')
                            ->visible(fn (Get $get) => filled($get('bestand')))
                            ->action(fn (Get $get, Set $set) => self::leesUit($get, $set)),
                    ])->key('uitleesActies'),
                    Hidden::make('twijfel')->dehydrated(false),
                    Hidden::make('overige_regels')->dehydrated(false),
                    Callout::make('Controleer deze velden')
                        ->description(fn (Get $get) => implode(' ', array_merge(
                            (array) $get('twijfel'),
                            self::overigeRegelsMeldingen((array) $get('overige_regels')),
                        )))
                        ->warning()
                        ->visible(fn (Get $get) => filled($get('twijfel')) || filled($get('overige_regels'))),
                ]),
            Grid::make(2)->columnSpanFull()->schema([
                Select::make('vervoerder')
                    ->label('Vervoerder')
                    ->options(fn () => Vervoerders::opties())
                    ->live()
                    ->required(),
                TextInput::make('factuurnummer')
                    ->label('Factuurnummer')
                    ->maxLength(255)
                    ->trim()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('vervoerder', $get('vervoerder')),
                    )
                    ->validationMessages(['unique' => 'Deze factuur van deze vervoerder staat er al.']),
                DatePicker::make('factuurdatum')->label('Factuurdatum'),
                TextInput::make('aantal_zendingen')->label('Aantal zendingen')->integer()->minValue(0),
                DatePicker::make('periode_van')->label('Periode van')->required(),
                DatePicker::make('periode_tot')->label('Periode tot en met')->required()->afterOrEqual('periode_van'),
                TextInput::make('bedrag')->label('Bedrag (excl. btw)')->numeric()->minValue(0.01)->prefix('€')->required(),
                TextInput::make('btw_bedrag')->label('Btw-bedrag')->numeric()->minValue(0)->prefix('€'),
            ]),
            Textarea::make('notitie')->label('Notitie')->rows(2)->columnSpanFull(),
            Hidden::make('bron')->default(Verzendfactuur::BRON_HANDMATIG),
        ]);
    }

    /** Stuurt de geüploade PDF naar Claude en zet de gevonden velden in het formulier. */
    public static function leesUit(Get $get, Set $set): void
    {
        $bestand = $get('bestand');
        $bestand = is_array($bestand) ? (array_values($bestand)[0] ?? null) : $bestand;
        $lezer = app(VerzendfactuurLezer::class);

        // Een nieuwe upload lezen we als Livewire-bestand; een opgeslagen PDF alleen via zijn
        // relatieve pad op de local-disk (de lezer weigert al het andere).
        $resultaat = match (true) {
            $bestand instanceof TemporaryUploadedFile => $lezer->leesUpload($bestand),
            is_string($bestand) && $bestand !== '' => $lezer->lees($bestand),
            default => ['gelukt' => false, 'fout' => 'Upload eerst een PDF.', 'velden' => [], 'twijfel' => [], 'overige_regels' => []],
        };

        if (! $resultaat['gelukt']) {
            Notification::make()->danger()->title('Uitlezen mislukt')->body($resultaat['fout'])->send();

            return;
        }

        foreach ($resultaat['velden'] as $veld => $waarde) {
            // Alleen gevonden waarden overnemen; wat al is ingevuld blijft staan als Claude niets vond.
            if ($waarde !== null) {
                $set($veld, $waarde);
            }
        }
        $set('bron', Verzendfactuur::BRON_AI);
        $set('twijfel', $resultaat['twijfel']);
        $set('overige_regels', $resultaat['overige_regels']);

        if (blank($get('notitie')) && filled($resultaat['overige_regels'])) {
            $set('notitie', implode(' ', self::overigeRegelsMeldingen($resultaat['overige_regels'])));
        }

        $melding = Notification::make()->title('Factuur uitgelezen');
        ($resultaat['twijfel'] === [] ? $melding->success()->body('Controleer de velden en sla op.') : $melding->warning()->body('Een deel van de velden moet je zelf controleren.'))->send();
    }

    /**
     * Meldingen voor regels die Claude bewust niet in het bedrag heeft meegeteld
     * (abonnementen, credit-/compensatieregels e.d.).
     *
     * @param  list<array{omschrijving: string, bedrag_excl_btw: ?float}>  $overigeRegels
     * @return list<string>
     */
    private static function overigeRegelsMeldingen(array $overigeRegels): array
    {
        return collect($overigeRegels)
            ->map(fn (array $regel) => 'Niet meegeteld: '.$regel['omschrijving']
                .($regel['bedrag_excl_btw'] === null ? '' : ' '.self::geld((float) $regel['bedrag_excl_btw']))
                .' — zet dit bij Vaste lasten indien het een terugkerende kost is.')
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(self::UITLEG)
            ->defaultSort(fn ($query) => $query->orderByDesc('periode_tot')->orderByDesc('id'))
            ->columns([
                TextColumn::make('vervoerder')->label('Vervoerder')->sortable(),
                TextColumn::make('factuurnummer')->label('Factuurnummer')->searchable()->placeholder('—'),
                TextColumn::make('periode_van')->label('Periode')->sortable()
                    ->formatStateUsing(fn (Verzendfactuur $record) => $record->periode_van->format('d-m-Y').' t/m '.$record->periode_tot->format('d-m-Y')),
                TextColumn::make('bedrag')->label('Bedrag (excl. btw)')->sortable()
                    ->formatStateUsing(fn ($state) => self::geld((float) $state)),
                TextColumn::make('schatting')->label('Schatting')
                    ->state(fn (Verzendfactuur $record) => self::correctie()->schattingVoor($record))
                    ->formatStateUsing(fn ($state) => self::geld((float) $state))
                    ->tooltip('Verzendtarief van alle labels van deze vervoerder in de factuurperiode'),
                TextColumn::make('factor')->label('Factor')
                    ->state(fn (Verzendfactuur $record) => self::correctie()->factorVoor($record) ?? 'geen labels')
                    ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, ',', '.') : $state)
                    ->color(fn ($state) => is_numeric($state) ? null : 'warning')
                    ->tooltip(fn ($state) => is_numeric($state) ? null : 'Geen labels in deze periode: de factuur telt niet mee in de factor.'),
                TextColumn::make('bron')->label('Bron')->badge()
                    ->formatStateUsing(fn (?string $state) => $state === Verzendfactuur::BRON_AI ? 'Claude' : 'Handmatig')
                    ->color(fn (?string $state) => $state === Verzendfactuur::BRON_AI ? 'info' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('vervoerder')->label('Vervoerder')
                    ->options(fn () => Vervoerders::opties()),
            ])
            ->recordActions([
                self::downloadActie(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function downloadActie(): Action
    {
        return Action::make('downloaden')
            ->label('PDF')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (?Verzendfactuur $record) => filled($record?->bestand) && Storage::disk(self::DISK)->exists($record->bestand))
            ->action(function (Verzendfactuur $record): ?StreamedResponse {
                // Verdedigingslaag: het pad komt uit de database, maar alleen een
                // vertrouwd pad (zie VerzendfactuurLezer::isVertrouwdPad()) wordt gedownload.
                if (! VerzendfactuurLezer::isVertrouwdPad($record->bestand)) {
                    Notification::make()->danger()->title('Bestand niet gevonden')->send();

                    return null;
                }

                return Storage::disk(self::DISK)->download(
                    $record->bestand,
                    str($record->vervoerder.'-'.($record->factuurnummer ?: $record->periode_van->format('Y-m')))->slug()->append('.pdf')->toString(),
                );
            });
    }

    /** Eén correctie-instantie per request, zodat facturen en schattingen één keer geladen worden. */
    private static function correctie(): VerzendfactuurCorrectie
    {
        return once(fn () => app(VerzendfactuurCorrectie::class));
    }

    private static function geld(float $bedrag): string
    {
        return '€ '.number_format($bedrag, 2, ',', '.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVerzendfacturen::route('/'),
            'create' => CreateVerzendfactuur::route('/create'),
            'edit' => EditVerzendfactuur::route('/{record}/edit'),
        ];
    }
}
