<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties;

use Filament\Widgets\Widget;
use Livewire\Attributes\Url;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieDashboard;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\Concerns\LeestAdvertentieCijfers;

/**
 * Per week, maand, kwartaal of jaar (eigen keuze, los van de groepering van het dashboard):
 * omzet, uitgaven, marge en winst met een gezondheidslabel, en of de laatste volle perioden
 * beter gaan dan de perioden ervoor. Toont minstens OVERZICHT[..]['toon'] perioden, zodat de
 * trend ook bij "Laatste 30 dagen" zichtbaar is; ervoor wordt nog genoeg opgehaald om de
 * eerste getoonde periode te kunnen vergelijken.
 */
class WeekTabel extends Widget
{
    use LeestAdvertentieCijfers;

    public const PER = [
        AdvertentieDashboard::WEEK => ['week', 'weken'],
        AdvertentieDashboard::MAAND => ['maand', 'maanden'],
        AdvertentieDashboard::KWARTAAL => ['kwartaal', 'kwartalen'],
        AdvertentieDashboard::JAAR => ['jaar', 'jaren'],
    ];

    #[Url(as: 'per')]
    public string $per = AdvertentieDashboard::WEEK;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'dashed-ecommerce-rendabiliteit::filament.widgets.advertenties.week-tabel';

    public function kies(string $per): void
    {
        $this->per = array_key_exists($per, self::PER) ? $per : AdvertentieDashboard::WEEK;
    }

    protected function getViewData(): array
    {
        $per = array_key_exists($this->per, self::PER) ? $this->per : AdvertentieDashboard::WEEK;
        $instelling = AdvertentieDashboard::OVERZICHT[$per];
        $periode = $this->periode();
        $tot = $periode->tot->copy();
        $laatsteBegin = AdvertentieDashboard::periodeBegin($tot, $per);
        $van = AdvertentieDashboard::periodeBegin($periode->van, $per)
            ->min(AdvertentieDashboard::periodenTerug($laatsteBegin, $per, $instelling['toon'] - 1));
        $terug = max($instelling['trend'], $instelling['jaarTerug'] ?? 0);
        $ophalenVan = AdvertentieDashboard::periodenTerug($van, $per, $terug);
        $eerste = AdvertentieDashboard::eersteOrderdag();

        if ($eerste !== null && $eerste->lt($tot)) {
            $ophalenVan = $ophalenVan->max(AdvertentieDashboard::periodeBegin($eerste, $per));
        }

        return [
            'per' => $per,
            'namen' => self::PER[$per],
            'jaarvergelijking' => $instelling['jaarTerug'] !== null,
            'overzicht' => AdvertentieDashboard::overzicht(
                app(AdvertentieDashboard::class)->reeks($ophalenVan, $tot, $per),
                $per,
                $van,
            ),
            'van' => $van,
            'tot' => $tot,
        ];
    }
}
