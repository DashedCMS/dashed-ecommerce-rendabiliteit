<?php

namespace Dashed\DashedEcommerceRendabiliteit\Support;

use Dashed\DashedCore\Models\BackgroundTask;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Het ophalen van advertentie-uitgaven in het takenicoon van dashed-core,
 * met een stap per koppeling. Zonder koppeling geen regel, en een oudere
 * dashed-core zonder het model geeft overal null: de administratie mag het
 * ophalen nooit tegenhouden (BackgroundTask zelf loopt al door rescue()).
 */
class AdvertentieTaak
{
    public static function begin(string $type, string $label, int $koppelingen, ?Authenticatable $user = null): ?BackgroundTask
    {
        if ($koppelingen < 1 || ! class_exists(BackgroundTask::class)) {
            return null;
        }

        return BackgroundTask::begin($type, $label, $koppelingen, $user, notify: false);
    }

    /**
     * @param  array<string, array{gelukt: bool, fout: ?string, dagen: int, totaal: float}>  $uitkomst
     */
    public static function sluit(?BackgroundTask $taak, array $uitkomst, ?string $url = null, ?string $urlLabel = null): void
    {
        if (! $taak) {
            return;
        }

        $samenvatting = collect($uitkomst)
            ->map(fn (array $stand, string $platform) => $stand['gelukt']
                ? "{$platform}: {$stand['dagen']} dagen, € ".number_format($stand['totaal'], 2, ',', '.')
                : "{$platform}: {$stand['fout']}")
            ->implode('. ');

        collect($uitkomst)->every('gelukt')
            ? $taak->finish($samenvatting.'.', $url, $urlLabel)
            : $taak->fail($samenvatting);
    }
}
