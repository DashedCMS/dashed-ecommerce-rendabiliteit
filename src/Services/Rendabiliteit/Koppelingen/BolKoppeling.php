<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\KoppelingInstellingen;

/**
 * Uitgaven uit de Advertising Reporting-API van Bol. Die geeft alleen een totaal
 * over een periode terug en gaat 30 dagen terug, dus per dag één verzoek.
 */
class BolKoppeling implements AdvertentieKoppeling
{
    use LeestApiAntwoord;

    private const DAGEN_TERUG = 30;

    private ?string $token = null;

    private int $tokenTot = 0;

    public function sleutel(): string
    {
        return KoppelingInstellingen::BOL;
    }

    public function platform(): string
    {
        return 'Bol';
    }

    public function isIngesteld(): bool
    {
        return KoppelingInstellingen::waarde('bol_client_id') !== null
            && KoppelingInstellingen::geheim('bol_client_secret') !== null;
    }

    public function vroegsteDag(): ?CarbonImmutable
    {
        return CarbonImmutable::today()->subDays(self::DAGEN_TERUG);
    }

    public function uitgaven(CarbonImmutable $van, CarbonImmutable $tot): array
    {
        $uitgaven = [];

        for ($dag = $van; $dag->lte($tot); $dag = $dag->addDay()) {
            $antwoord = $this->verzoek(fn () => Http::timeout(30)
                ->retry(3, 5000, fn ($fout) => $fout instanceof RequestException && $fout->response->status() === 429, throw: false)
                ->withToken($this->toegangstoken())
                ->acceptJson()
                ->get('https://api.bol.com/advertiser/sponsored-products/reporting/performance/advertiser', [
                    'period-start-date' => $dag->toDateString(),
                    'period-end-date' => $dag->toDateString(),
                ]));

            $uitgaven[$dag->toDateString()] = (float) ($antwoord->json('cost') ?? 0);
        }

        return $uitgaven;
    }

    /** Bol staat weinig tokenverzoeken toe, dus hergebruiken tot vlak voor het verlopen. */
    private function toegangstoken(): string
    {
        if ($this->token !== null && time() < $this->tokenTot) {
            return $this->token;
        }

        $antwoord = $this->verzoek(fn () => Http::timeout(30)
            ->withBasicAuth((string) KoppelingInstellingen::waarde('bol_client_id'), (string) KoppelingInstellingen::geheim('bol_client_secret'))
            ->asForm()
            ->acceptJson()
            ->post('https://login.bol.com/token', ['grant_type' => 'client_credentials']));

        $this->token = (string) ($antwoord->json('access_token') ?: throw new KoppelingFout('Bol gaf geen toegangstoken.'));
        $this->tokenTot = time() + max(0, (int) $antwoord->json('expires_in', 299) - 30);

        return $this->token;
    }

    private function foutUit(Response $antwoord): ?string
    {
        return $antwoord->json('detail') ?? $antwoord->json('title');
    }
}
