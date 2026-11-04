<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit\Concerns;

use Dashed\DashedEcommerceRendabiliteit\Models\RendabiliteitGenegeerd;

/**
 * "Negeren" en "Niet meer negeren" voor de aandachtspunten op de rapportpagina. Alleen sleutels
 * die in de huidige aandachtspunten voorkomen kunnen genegeerd worden; de omschrijving komt van
 * de server, niet uit de browser. Vereist een computed property $aandachtspunten.
 */
trait NegeertAandachtspunten
{
    public function negeer(string $sleutel): void
    {
        abort_unless(static::canAccess(), 403);

        $rij = $this->vindAandachtspunt($sleutel);

        if ($rij !== null) {
            $this->slaNegerenOp($rij);
        }

        unset($this->aandachtspunten);
    }

    /** Negeert alle zichtbare items van één soort in "Wat mist" (bijv. alle kostprijzen). */
    public function negeerSoort(string $soort): void
    {
        abort_unless(static::canAccess(), 403);

        foreach ($this->aandachtspunten['mist'] as $item) {
            if ($item['soort'] === $soort) {
                $this->slaNegerenOp($item);
            }
        }

        unset($this->aandachtspunten);
    }

    public function nietMeerNegeren(string $sleutel): void
    {
        abort_unless(static::canAccess(), 403);

        RendabiliteitGenegeerd::query()->where('sleutel', $sleutel)->delete();
        unset($this->aandachtspunten);
    }

    /** Zichtbare of al genegeerde rij met deze sleutel, uit elk blok. */
    private function vindAandachtspunt(string $sleutel): ?array
    {
        $punten = $this->aandachtspunten;
        $rijen = array_merge(
            $punten['marge']['rijen'],
            $punten['kanalen'],
            $punten['mist'],
            $punten['advertenties'],
            ...array_values($punten['genegeerd']),
        );

        if (! empty($punten['outlet'])) {
            $rijen[] = $punten['outlet'];
        }

        foreach ($rijen as $rij) {
            if ($rij['sleutel'] === $sleutel) {
                return $rij;
            }
        }

        return null;
    }

    private function slaNegerenOp(array $rij): void
    {
        $omschrijving = $rij['titel'] ?? (isset($rij['groep']) ? $rij['groep'].' · '.$rij['maat'] : trim(($rij['maand'] ?? '').' '.($rij['kanaal'] ?? '')));

        RendabiliteitGenegeerd::query()->updateOrCreate(
            ['sleutel' => $rij['sleutel']],
            ['omschrijving' => mb_substr($omschrijving, 0, 255), 'user_id' => auth()->id()],
        );
    }
}
