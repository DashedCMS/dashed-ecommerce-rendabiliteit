<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit;

use UnitEnum;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Pages\Dashboard;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Dashed\DashedEcommerceRendabiliteit\Support\AdvertentiePeriode;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\RoasChart;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\WeekTabel;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\PlatformTabel;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\AdvertentieKpis;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\UitgavenOmzetChart;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\OmzetPerKanaalChart;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\UitgavenVerdelingChart;

/**
 * Advertentiedashboard: Meta- en Google-uitgaven tegenover webshopomzet en marge, met ROAS,
 * MER en break-even. Cijfers uit AdvertentieDashboard; filters gelden voor alle widgets.
 */
class AdvertentiesPage extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'rendabiliteit/advertenties';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static string|UnitEnum|null $navigationGroup = 'Rendabiliteit';

    protected static ?string $navigationLabel = 'Advertenties';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Advertenties';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->can('view_statistics');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('periode')
                ->label('Periode')
                ->options(AdvertentiePeriode::PERIODES)
                ->default('90d')
                ->selectablePlaceholder(false)
                ->live(),
            DatePicker::make('van')
                ->label('Van')
                ->native(false)
                ->displayFormat('j M Y')
                ->visible(fn (Get $get) => $get('periode') === 'eigen'),
            DatePicker::make('tot')
                ->label('Tot en met')
                ->native(false)
                ->displayFormat('j M Y')
                ->visible(fn (Get $get) => $get('periode') === 'eigen'),
            Select::make('groepering')
                ->label('Per')
                ->options(AdvertentiePeriode::GROEPERINGEN)
                ->default('auto')
                ->selectablePlaceholder(false),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            AdvertentieKpis::class,
            UitgavenOmzetChart::class,
            RoasChart::class,
            UitgavenVerdelingChart::class,
            OmzetPerKanaalChart::class,
            PlatformTabel::class,
            WeekTabel::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
