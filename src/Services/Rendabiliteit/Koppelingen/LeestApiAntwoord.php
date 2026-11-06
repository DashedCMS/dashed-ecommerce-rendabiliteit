<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\ConnectionException;

trait LeestApiAntwoord
{
    /** Voert het verzoek uit; een onbereikbaar platform of foutstatus wordt een KoppelingFout. */
    private function verzoek(Closure $verzoek): Response
    {
        try {
            $antwoord = $verzoek();
        } catch (ConnectionException) {
            throw new KoppelingFout("{$this->platform()} is niet bereikbaar.");
        }

        if (! $antwoord->successful()) {
            $melding = Str::limit(trim((string) $this->foutUit($antwoord)), 240);

            throw new KoppelingFout("{$this->platform()} gaf status {$antwoord->status()}".($melding !== '' ? ": {$melding}" : '.'));
        }

        return $antwoord;
    }

    private function controleerValuta(?string $valuta): void
    {
        if ($valuta !== null && $valuta !== '' && mb_strtoupper($valuta) !== 'EUR') {
            throw new KoppelingFout("Het {$this->platform()}-account rekent in {$valuta}; alleen euro wordt ondersteund.");
        }
    }

    abstract public function platform(): string;

    abstract private function foutUit(Response $antwoord): ?string;
}
