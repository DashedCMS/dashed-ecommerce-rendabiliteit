<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Illuminate\Support\Facades\DB;
use Dashed\DashedEcommerceRendabiliteit\Models\AdvertentieUitgave;

/**
 * Advertentie-uitgaven per dag via de API (`POST /api/advertentie-uitgaven`), voor de dagelijkse
 * automatische check van Meta, Google en Bol. Upsert op datum + platform: opnieuw sturen overschrijft
 * (ook een handmatige of geïmporteerde regel), zodat een dag die het platform later bijwerkt gewoon
 * nog eens gestuurd kan worden. Bron `api`; de rapportcache wordt één keer gewist.
 */
class AdvertentieApiOpslag
{
    public const BRON = 'api';

    /**
     * @param  list<array{datum: string, platform: string, bedrag: float|int|string}>  $regels
     * @return array{nieuw: int, bijgewerkt: int, gelijk: int}
     */
    public function sla(array $regels): array
    {
        $telling = ['nieuw' => 0, 'bijgewerkt' => 0, 'gelijk' => 0];

        DB::transaction(function () use ($regels, &$telling) {
            foreach ($regels as $regel) {
                $platform = AdvertentiePlatformen::normaliseer($regel['platform']);
                $bedrag = round((float) $regel['bedrag'], 2);

                $bestaand = AdvertentieUitgave::query()
                    ->whereDate('datum', $regel['datum'])
                    ->where('platform', $platform)
                    ->first();

                if (! $bestaand) {
                    (new AdvertentieUitgave([
                        'datum' => $regel['datum'],
                        'platform' => $platform,
                        'bedrag' => $bedrag,
                        'bron' => self::BRON,
                    ]))->saveQuietly();
                    $telling['nieuw']++;

                    continue;
                }

                if (abs((float) $bestaand->bedrag - $bedrag) < 0.005 && $bestaand->bron === self::BRON) {
                    $telling['gelijk']++;

                    continue;
                }

                $bestaand->fill(['bedrag' => $bedrag, 'bron' => self::BRON])->saveQuietly();
                $telling['bijgewerkt']++;
            }
        });

        if ($telling['nieuw'] + $telling['bijgewerkt'] > 0) {
            RendabiliteitCache::wis();
        }

        return $telling;
    }
}
