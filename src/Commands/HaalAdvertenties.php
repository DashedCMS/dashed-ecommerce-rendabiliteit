<?php

namespace Dashed\DashedEcommerceRendabiliteit\Commands;

use Throwable;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen\AdvertentieKoppelingen;

/** Haalt de advertentie-uitgaven op van de ingestelde koppelingen (Meta, Google, Bol). */
class HaalAdvertenties extends Command
{
    protected $signature = 'rendabiliteit:haal-advertenties
        {--van= : Eerste dag (JJJJ-MM-DD), standaard 7 dagen terug}
        {--tot= : Laatste dag (JJJJ-MM-DD), standaard gisteren}
        {--platform= : Alleen meta, google of bol}';

    protected $description = 'Haalt de advertentie-uitgaven per dag op bij Meta, Google en Bol';

    public function handle(AdvertentieKoppelingen $koppelingen): int
    {
        try {
            $van = $this->option('van') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->option('van')) : null;
            $tot = $this->option('tot') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->option('tot')) : null;
        } catch (Throwable) {
            $this->error('Gebruik datums als JJJJ-MM-DD.');

            return self::INVALID;
        }

        if ($tot?->isFuture() || ($van && $van->gt($tot ?? CarbonImmutable::yesterday()))) {
            $this->error('De periode klopt niet: --van moet vóór --tot liggen en --tot mag niet in de toekomst liggen.');

            return self::INVALID;
        }

        $uitkomst = $koppelingen->haalOp($van, $tot, $this->option('platform') ?: null);

        if ($uitkomst === []) {
            $this->info('Geen koppeling aan of ingevuld.');

            return self::SUCCESS;
        }

        foreach ($uitkomst as $platform => $stand) {
            $stand['gelukt']
                ? $this->info("{$platform}: {$stand['dagen']} dagen, € ".number_format($stand['totaal'], 2, ',', '.'))
                : $this->error("{$platform}: {$stand['fout']}");
        }

        return collect($uitkomst)->every('gelukt') ? self::SUCCESS : self::FAILURE;
    }
}
