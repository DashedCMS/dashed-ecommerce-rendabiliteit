<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties;

use Filament\Widgets\Widget;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieDashboard;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\Concerns\LeestAdvertentieCijfers;

/** Cijfers per platform naast elkaar, plus het totaal (MER) als controle op de grafieken. */
class PlatformTabel extends Widget
{
    use LeestAdvertentieCijfers;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'dashed-ecommerce-rendabiliteit::filament.widgets.advertenties.platform-tabel';

    protected function getViewData(): array
    {
        return [
            'totaal' => AdvertentieDashboard::totaal($this->reeks()),
            'periode' => $this->periode(),
        ];
    }
}
