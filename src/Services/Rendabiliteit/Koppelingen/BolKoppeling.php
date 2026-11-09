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

    /**
     * Bol eist een vendor-type als Accept (een 406 noemt alleen het patroon) en documenteert niet
     * welk voor de Advertising API; we proberen ze op volgorde en onthouden wat werkt.
     */
    public const ACCEPT_KANDIDATEN = [
        'application/vnd.advertiser.v11+json',
        'application/vnd.retailer.v11+json',
        'application/vnd.advertiser.v10+json',
        'application/json',
    ];

    private ?string $accept = null;

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
            $antwoord = $this->rapport($dag);

            $uitgaven[$dag->toDateString()] = (float) ($antwoord->json('cost') ?? 0);
        }

        return $uitgaven;
    }

    private function rapport(CarbonImmutable $dag): Response
    {
        $kandidaten = $this->accept !== null
            ? [$this->accept]
            : (array_values(array_filter([config('dashed-ecommerce-rendabiliteit.koppelingen.bol_accept')])) ?: self::ACCEPT_KANDIDATEN);

        foreach ($kandidaten as $i => $accept) {
            try {
                $antwoord = $this->verzoek(fn () => Http::timeout(30)
                    ->retry(3, 5000, fn ($fout) => $fout instanceof RequestException && $fout->response->status() === 429, throw: false)
                    ->withToken($this->toegangstoken())
                    ->accept($accept)
                    ->get('https://api.bol.com/advertiser/sponsored-products/reporting/performance/advertiser', [
                        'period-start-date' => $dag->toDateString(),
                        'period-end-date' => $dag->toDateString(),
                    ]));
            } catch (KoppelingFout $fout) {
                // Alleen een geweigerde Accept-header (406) is reden om de volgende te proberen.
                if (! str_contains($fout->getMessage(), 'status 406') || $i === count($kandidaten) - 1) {
                    throw $fout;
                }

                continue;
            }

            $this->accept = $accept;

            return $antwoord;
        }

        throw new KoppelingFout('Bol accepteerde geen enkele Accept-header.');
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
