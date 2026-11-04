<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit;

use UnitEnum;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Actions\Action;
use InvalidArgumentException;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\MaandRapport;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\PeriodeTotaal;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Aandachtspunten;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Pdf\RendabiliteitPdf;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Rendabiliteitsrapport;
use Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit\Concerns\NegeertAandachtspunten;

/**
 * Alleen-lezen rendabiliteitsrapport per maand: omzet, kostprijs, orderkosten,
 * marge, advertenties, vaste lasten en winst, met waarschuwingen en break-even.
 * Alle bedragen excl. btw; de berekening zit in Rendabiliteitsrapport.
 */
class RendabiliteitPage extends Page
{
    use NegeertAandachtspunten;

    /** Langere periodes gelden als ongeldige invoer (elke maand is een eigen berekening). */
    private const MAX_MAANDEN = 36;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string|UnitEnum|null $navigationGroup = 'Rendabiliteit';

    protected static ?string $navigationLabel = 'Rapport';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'rendabiliteit/rapport';

    protected static ?string $title = 'Rendabiliteit';

    protected string $view = 'dashed-ecommerce-rendabiliteit::filament.pages.rendabiliteit.rapport';

    /** 'Y-m' */
    public string $van = '';

    /** 'Y-m' */
    public string $tot = '';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->can('view_statistics');
    }

    public function mount(): void
    {
        [$van, $tot] = self::standaardPeriode();
        $this->van = $van->format('Y-m');
        $this->tot = $tot->format('Y-m');
    }

    /**
     * "PDF exporteren": kies een soort (per maand, periode, vergelijken, progressie) en download
     * de PDF. Standaard de periode die nu op de pagina staat; bij vergelijken is B die periode en
     * A de even lange periode ervoor.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdfExporteren')
                ->label('PDF exporteren')
                ->icon('heroicon-o-document-arrow-down')
                ->visible(fn () => static::canAccess())
                ->modalHeading('Rendabiliteit als PDF')
                ->modalSubmitActionLabel('Downloaden')
                ->modalWidth(Width::Large)
                ->fillForm(fn () => $this->pdfStandaardwaarden())
                ->schema([
                    Select::make('soort')
                        ->label('Soort')
                        ->options(RendabiliteitPdf::SOORTEN)
                        ->required()
                        ->live()
                        ->helperText(fn (Get $get) => match ($get('soort')) {
                            'maand' => 'Eén maand in detail: kanalen, marketingkanalen, advertenties, verzending en productgroepen.',
                            'periode' => 'Eén rij per maand met een totaalrij, optioneel gevolgd door het detail van elke maand.',
                            'vergelijken' => 'A en B naast elkaar met het verschil in € en %. Zelfde maand bij van en tot = één maand.',
                            'progressie' => 'Staafgrafieken per maand (omzet, marge, winst, ROAS) en de wijziging t.o.v. de vorige maand.',
                            default => null,
                        }),
                    self::maandVeld('maand', 'Maand')->visible(fn (Get $get) => $get('soort') === 'maand'),
                    Grid::make(2)
                        ->visible(fn (Get $get) => in_array($get('soort'), ['periode', 'progressie'], true))
                        ->schema([self::maandVeld('van', 'Van'), self::maandVeld('tot', 'Tot en met')]),
                    Checkbox::make('met_detail')
                        ->label('Detail van elke maand toevoegen (een pagina per maand)')
                        ->visible(fn (Get $get) => $get('soort') === 'periode'),
                    Grid::make(2)
                        ->visible(fn (Get $get) => $get('soort') === 'vergelijken')
                        ->schema([
                            self::maandVeld('a_van', 'A van'),
                            self::maandVeld('a_tot', 'A tot en met'),
                            self::maandVeld('b_van', 'B van'),
                            self::maandVeld('b_tot', 'B tot en met'),
                        ]),
                ])
                ->action(function (array $data) {
                    $service = app(RendabiliteitPdf::class);

                    try {
                        $gegevens = $service->gegevens($data['soort'], $data);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('PDF niet gemaakt')->body($e->getMessage())->send();

                        return null;
                    }

                    $pdf = $service->pdf($gegevens);

                    return response()->streamDownload(fn () => print($pdf), $service->bestandsnaam($gegevens), ['Content-Type' => 'application/pdf']);
                }),
        ];
    }

    /** @return array<string, mixed> */
    public function pdfStandaardwaarden(): array
    {
        [$van, $tot] = $this->periode;
        $lengte = (int) $van->diffInMonths($tot) + 1;

        return [
            'soort' => 'periode',
            'maand' => $tot->format('Y-m'),
            'van' => $van->format('Y-m'),
            'tot' => $tot->format('Y-m'),
            'met_detail' => false,
            'a_van' => $van->copy()->subMonthsNoOverflow($lengte)->format('Y-m'),
            'a_tot' => $van->copy()->subMonthNoOverflow()->format('Y-m'),
            'b_van' => $van->format('Y-m'),
            'b_tot' => $tot->format('Y-m'),
        ];
    }

    private static function maandVeld(string $naam, string $label): TextInput
    {
        return TextInput::make($naam)
            ->label($label)
            ->type('month')
            ->required()
            ->regex('/^20\d{2}-(0[1-9]|1[0-2])$/')
            ->validationMessages(['regex' => 'Kies een maand.']);
    }

    /** Laatste 6 volledige maanden plus de lopende maand. */
    public static function standaardPeriode(): array
    {
        return [now()->startOfMonth()->subMonthsNoOverflow(6), now()->startOfMonth()];
    }

    /**
     * Een handmatig aangepaste of halve invoer mag geen 500 geven: bij onzin,
     * een omgekeerde of een te lange periode valt het rapport terug op de standaard.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function getPeriodeProperty(): array
    {
        $van = self::parseMaand($this->van);
        $tot = self::parseMaand($this->tot);

        if ($van === null || $tot === null || $van->gt($tot) || $van->diffInMonths($tot) >= self::MAX_MAANDEN) {
            return self::standaardPeriode();
        }

        return [$van, $tot];
    }

    /** @return Collection<int, MaandRapport> */
    public function getRapportenProperty(): Collection
    {
        [$van, $tot] = $this->periode;

        return app(Rendabiliteitsrapport::class)->periode($van, $tot);
    }

    /** Te weinig marge, wat mist en advertenties onder de break-even voor de periode (zie Aandachtspunten). */
    public function getAandachtspuntenProperty(): array
    {
        [$van, $tot] = $this->periode;

        return app(Aandachtspunten::class)->voorPeriode($van, $tot);
    }

    /** Waarschuwingen van alle maanden samengevoegd per code. */
    public function getWaarschuwingenProperty(): array
    {
        return PeriodeTotaal::waarschuwingen($this->rapporten);
    }

    /** Totaalrij over de periode (marge % over de opgetelde omzet, niet het gemiddelde van de maanden). */
    public function getTotaalProperty(): array
    {
        return PeriodeTotaal::totaal($this->rapporten);
    }

    /** Break-even van de laatste volledige maand in de periode (null als er geen is of de marge ≤ 0). */
    public function getBreakEvenLaatsteMaandProperty(): ?array
    {
        $huidige = now()->format('Y-m');
        $rapport = $this->rapporten->filter(fn (MaandRapport $r) => $r->maand < $huidige)->last();

        return $rapport ? [
            'maand' => $rapport->maand,
            'bedrag' => $rapport->breakEven,
            'zonderKosten' => $rapport->advertenties + $rapport->vasteLasten == 0,
        ] : null;
    }

    /** Geen advertenties en geen vaste lasten in de periode: dan zegt een break-even niets. */
    public function getBreakEvenZonderKostenProperty(): bool
    {
        return $this->totaal['advertenties'] + $this->totaal['vasteLasten'] == 0;
    }

    /**
     * Gemiddelde break-even: gemiddelde advertenties + vaste lasten per maand,
     * gedeeld door het margepercentage over de hele periode.
     */
    public function getBreakEvenGemiddeldProperty(): ?float
    {
        return PeriodeTotaal::breakEvenGemiddeld($this->rapporten);
    }

    public static function geld(?float $bedrag): string
    {
        return $bedrag === null ? '—' : '€ '.number_format($bedrag, 2, ',', '.');
    }

    public static function procent(float $waarde): string
    {
        return number_format($waarde, 1, ',', '.').'%';
    }

    public static function maandLabel(string $maand): string
    {
        return Carbon::createFromFormat('!Y-m', $maand)->locale('nl')->translatedFormat('F Y');
    }

    private static function parseMaand(string $waarde): ?Carbon
    {
        if (! preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', trim($waarde))) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m', trim($waarde))->startOfMonth();
    }
}
