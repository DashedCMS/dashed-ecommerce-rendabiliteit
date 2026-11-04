<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\VasteLasten;

use UnitEnum;
use BackedEnum;
use Carbon\Carbon;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Actions\DeleteAction;
use Illuminate\Auth\Access\Response;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Dashed\DashedEcommerceRendabiliteit\Models\VasteLast;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\VasteLasten\Pages\EditVasteLast;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\VasteLasten\Pages\CreateVasteLast;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\VasteLasten\Pages\ListVasteLasten;

/** Vaste maandlasten (excl. btw) voor het rendabiliteitsrapport. */
class VasteLastResource extends Resource
{
    protected static ?string $model = VasteLast::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|UnitEnum|null $navigationGroup = 'Rendabiliteit';

    protected static ?string $navigationLabel = 'Vaste lasten';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'rendabiliteit/vaste-lasten';

    protected static ?string $modelLabel = 'vaste last';

    protected static ?string $pluralModelLabel = 'vaste lasten';

    /** Alle acties alleen met view_statistics. */
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        return auth()->check() && auth()->user()->can('view_statistics') ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        $eersteVanDeMaand = fn ($state) => $state ? Carbon::parse($state)->startOfMonth()->toDateString() : null;

        return $schema->components([
            TextInput::make('omschrijving')
                ->label('Omschrijving')
                ->maxLength(255)
                ->required()
                ->columnSpanFull(),
            TextInput::make('bedrag_per_maand')
                ->label('Bedrag per maand (excl. btw)')
                ->numeric()
                ->prefix('€')
                ->required(),
            DatePicker::make('vanaf')
                ->label('Vanaf')
                ->helperText('Opgeslagen als de 1e van de maand.')
                ->default(fn () => now()->startOfMonth()->toDateString())
                ->dehydrateStateUsing($eersteVanDeMaand)
                ->required(),
            DatePicker::make('tot')
                ->label('Tot en met (optioneel)')
                ->helperText('Laatste maand waarin de last meetelt; leeg = loopt door.')
                ->dehydrateStateUsing($eersteVanDeMaand)
                ->afterOrEqual('vanaf'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $maand = fn ($state) => $state ? Carbon::parse($state)->locale('nl')->translatedFormat('F Y') : '';

        return $table
            ->defaultSort('omschrijving')
            ->columns([
                TextColumn::make('omschrijving')->label('Omschrijving')->searchable()->sortable(),
                TextColumn::make('bedrag_per_maand')->label('Per maand (excl. btw)')
                    ->formatStateUsing(fn ($state) => '€ '.number_format((float) $state, 2, ',', '.'))
                    ->sortable(),
                TextColumn::make('vanaf')->label('Vanaf')->formatStateUsing($maand)->sortable(),
                TextColumn::make('tot')->label('Tot en met')->formatStateUsing($maand)->placeholder('—')->sortable(),
                IconColumn::make('loopt_nu')->label('Loopt nu')
                    ->state(fn (VasteLast $record) => self::looptNu($record))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** Zelfde regel als VasteLast::scopeLopendIn() voor de lopende maand. */
    public static function looptNu(VasteLast $record): bool
    {
        $eerste = now()->startOfMonth()->startOfDay();

        return $record->vanaf !== null
            && $record->vanaf->lte($eerste)
            && ($record->tot === null || $record->tot->gte($eerste));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVasteLasten::route('/'),
            'create' => CreateVasteLast::route('/create'),
            'edit' => EditVasteLast::route('/{record}/edit'),
        ];
    }
}
