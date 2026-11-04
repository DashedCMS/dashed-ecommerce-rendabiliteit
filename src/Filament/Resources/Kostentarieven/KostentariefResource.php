<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven;

use UnitEnum;
use BackedEnum;
use Carbon\Carbon;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Filament\Tables\Grouping\Group;
use Illuminate\Auth\Access\Response;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\SelectFilter;
use Filament\Schemas\Components\Utilities\Get;
use Dashed\DashedEcommerceRendabiliteit\Models\Kostentarief;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentiePlatformen;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\OrderKostenBerekenaar;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\Pages\EditKostentarief;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\Pages\CreateKostentarief;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\Pages\ListKostentarieven;

/** Tarieven met ingangsdatum voor het rendabiliteitsrapport; een wijziging is een nieuwe regel. */
class KostentariefResource extends Resource
{
    public const LABELS = [
        'verzending' => 'Verzending',
        'verpakking' => 'Verpakking',
        'betaalmethode' => 'Betaalmethode',
        'etsy_commissie' => 'Etsy-commissie',
        'advertentie_dag' => 'Advertenties per dag',
    ];

    public const UITLEG = "Een tarief wijzigen? Voeg een nieuwe regel toe met een latere 'geldig vanaf'; oude maanden blijven dan kloppen.";

    protected static ?string $model = Kostentarief::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|UnitEnum|null $navigationGroup = 'Rendabiliteit';

    protected static ?string $navigationLabel = 'Tarieven';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'rendabiliteit/tarieven';

    protected static ?string $modelLabel = 'tarief';

    protected static ?string $pluralModelLabel = 'tarieven';

    /** Alle acties (bekijken, aanmaken, bewerken, verwijderen) alleen met view_statistics. */
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        return auth()->check() && auth()->user()->can('view_statistics') ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(self::UITLEG)->info()->columnSpanFull(),
            Select::make('soort')
                ->label('Soort')
                ->options(array_intersect_key(self::LABELS, array_flip(Kostentarief::SOORTEN)))
                ->required()
                ->live(),
            TextInput::make('sleutel')
                ->label('Sleutel')
                ->required()
                ->trim()
                ->maxLength(255)
                // Valideer op de genormaliseerde waarde: anders ziet 'facebook' naast een bestaand
                // 'Meta'-tarief er als uniek uit, terwijl opslaan ze allebei naar 'Meta' normaliseert
                // en op de database-index (soort, sleutel, geldig_vanaf) knalt.
                ->mutateStateForValidationUsing(
                    fn (?string $state, Get $get) => $get('soort') === 'advertentie_dag' ? AdvertentiePlatformen::normaliseer($state) : $state,
                )
                // Zelfde sleutel als de database-index (soort, sleutel, geldig_vanaf): liever een melding dan een 500.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                        ->where('soort', $get('soort'))
                        ->where('geldig_vanaf', $get('geldig_vanaf') ? Carbon::parse($get('geldig_vanaf'))->toDateString() : null),
                )
                ->validationMessages([
                    'unique' => 'Er bestaat al een tarief voor deze sleutel op deze datum; kies een andere datum of bewerk dat tarief.',
                ])
                ->datalist(fn (Get $get) => self::sleutelSuggesties($get('soort')))
                ->helperText('Verzending: pakkettype|land (bijv. pakket|NL); verpakking: pakket; Etsy: etsy; betaalmethode: de naam zoals in de betaling; advertenties per dag: het platform (Google, Meta, Pinterest, Bol).'),
            TextInput::make('vast_bedrag')
                ->label('Vast bedrag (excl. btw)')
                ->numeric()
                ->prefix('€')
                ->default(0)
                ->required(),
            TextInput::make('percentage')
                ->label('Percentage')
                ->numeric()
                ->suffix('%')
                ->default(0)
                ->required(),
            DatePicker::make('geldig_vanaf')
                ->label('Geldig vanaf')
                ->default(fn () => now()->toDateString())
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(self::UITLEG)
            ->defaultSort(fn ($query) => $query->orderBy('soort')->orderBy('sleutel')->orderByDesc('geldig_vanaf'))
            ->groups([
                Group::make('soort')
                    ->label('Soort')
                    ->getTitleFromRecordUsing(fn (Kostentarief $record) => self::LABELS[$record->soort] ?? $record->soort),
            ])
            ->defaultGroup('soort')
            ->columns([
                TextColumn::make('soort')->label('Soort')
                    ->formatStateUsing(fn (?string $state) => self::LABELS[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('sleutel')->label('Sleutel')->searchable()->sortable(),
                TextColumn::make('vast_bedrag')->label('Vast bedrag')
                    ->formatStateUsing(fn ($state) => '€ '.number_format((float) $state, 2, ',', '.')),
                TextColumn::make('percentage')->label('Percentage')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.').'%'),
                TextColumn::make('geldig_vanaf')->label('Geldig vanaf')->date('d-m-Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('soort')->label('Soort')->options(self::LABELS),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** @return array<int, string> */
    public static function sleutelSuggesties(?string $soort): array
    {
        return match ($soort) {
            'verzending' => ['pakket|NL', 'pakket|BE', 'pakket|overig', 'brievenbus|NL', 'brievenbus|BE', 'brievenbus|overig'],
            'verpakking' => ['pakket'],
            'etsy_commissie' => ['etsy'],
            'betaalmethode' => self::betaalmethodes(),
            'advertentie_dag' => AdvertentiePlatformen::BEKEND,
            default => [],
        };
    }

    /** Normaliseert de sleutel van een tarief 'advertentie_dag' naar een vaste platformnaam. */
    public static function normaliseerSleutel(array $data): array
    {
        if (($data['soort'] ?? null) === 'advertentie_dag') {
            $data['sleutel'] = AdvertentiePlatformen::normaliseer($data['sleutel'] ?? null);
        }

        return $data;
    }

    /** payment_method-waarden uit betaalde betalingen, zonder de kosteloze methodes. */
    public static function betaalmethodes(): array
    {
        return DB::table('dashed__order_payments')
            ->where('status', 'paid')
            ->whereNotNull('payment_method')
            ->distinct()
            ->orderBy('payment_method')
            ->pluck('payment_method')
            ->map(fn ($methode) => trim((string) $methode))
            ->reject(fn (string $methode) => in_array(mb_strtolower($methode), OrderKostenBerekenaar::KOSTELOOS, true))
            ->unique()
            ->values()
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKostentarieven::route('/'),
            'create' => CreateKostentarief::route('/create'),
            'edit' => EditKostentarief::route('/{record}/edit'),
        ];
    }
}
