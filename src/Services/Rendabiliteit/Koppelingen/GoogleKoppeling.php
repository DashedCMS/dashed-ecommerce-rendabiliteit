<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\KoppelingInstellingen;

/** Uitgaven per dag uit de Google Ads-API (OAuth met een refresh token). */
class GoogleKoppeling implements AdvertentieKoppeling
{
    use LeestApiAntwoord;

    public function sleutel(): string
    {
        return KoppelingInstellingen::GOOGLE;
    }

    public function platform(): string
    {
        return 'Google';
    }

    public function isIngesteld(): bool
    {
        return $this->klant('google_customer_id') !== ''
            && KoppelingInstellingen::waarde('google_client_id') !== null
            && KoppelingInstellingen::geheim('google_client_secret') !== null
            && KoppelingInstellingen::geheim('google_refresh_token') !== null;
    }

    public function vroegsteDag(): ?CarbonImmutable
    {
        return null;
    }

    public function uitgaven(CarbonImmutable $van, CarbonImmutable $tot): array
    {
        $versie = config('dashed-ecommerce-rendabiliteit.koppelingen.google_versie');
        $query = sprintf(
            "SELECT segments.date, metrics.cost_micros, customer.currency_code FROM customer WHERE segments.date BETWEEN '%s' AND '%s'",
            $van->toDateString(),
            $tot->toDateString(),
        );
        $kop = array_filter([
            'developer-token' => KoppelingInstellingen::geheim('google_developer_token'),
            'login-customer-id' => $this->klant('google_login_customer_id') ?: null,
        ]);
        $toegang = $this->toegangstoken();

        $antwoord = $this->verzoek(fn () => Http::timeout(60)
            ->withToken($toegang)
            ->withHeaders($kop)
            ->acceptJson()
            ->post("https://googleads.googleapis.com/{$versie}/customers/{$this->klant('google_customer_id')}/googleAds:searchStream", ['query' => $query]));

        $uitgaven = [];

        foreach ($antwoord->json() ?? [] as $deel) {
            foreach ($deel['results'] ?? [] as $rij) {
                $this->controleerValuta($rij['customer']['currencyCode'] ?? null);
                $dag = $rij['segments']['date'];
                $uitgaven[$dag] = ($uitgaven[$dag] ?? 0.0) + ((float) ($rij['metrics']['costMicros'] ?? 0)) / 1_000_000;
            }
        }

        return $uitgaven;
    }

    private function toegangstoken(): string
    {
        $antwoord = $this->verzoek(fn () => Http::timeout(30)->asForm()->post('https://www.googleapis.com/oauth2/v3/token', [
            'grant_type' => 'refresh_token',
            'client_id' => KoppelingInstellingen::waarde('google_client_id'),
            'client_secret' => KoppelingInstellingen::geheim('google_client_secret'),
            'refresh_token' => KoppelingInstellingen::geheim('google_refresh_token'),
        ]));

        return (string) ($antwoord->json('access_token') ?: throw new KoppelingFout('Google gaf geen toegangstoken.'));
    }

    private function klant(string $veld): string
    {
        return preg_replace('/\D/', '', (string) KoppelingInstellingen::waarde($veld));
    }

    private function foutUit(Response $antwoord): ?string
    {
        $json = $antwoord->json();

        // searchStream geeft een fout als lijst, de token-endpoint als object.
        return $json[0]['error']['message'] ?? $json['error']['message'] ?? $json['error_description'] ?? null;
    }
}
