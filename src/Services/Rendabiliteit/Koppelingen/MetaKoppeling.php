<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\KoppelingInstellingen;

/** Uitgaven per dag uit de Insights-API van het advertentieaccount. */
class MetaKoppeling implements AdvertentieKoppeling
{
    use LeestApiAntwoord;

    private const MAX_PAGINAS = 50;

    public function sleutel(): string
    {
        return KoppelingInstellingen::META;
    }

    public function platform(): string
    {
        return 'Meta';
    }

    public function isIngesteld(): bool
    {
        return $this->account() !== '' && KoppelingInstellingen::geheim('meta_token') !== null;
    }

    public function vroegsteDag(): ?CarbonImmutable
    {
        return null;
    }

    public function uitgaven(CarbonImmutable $van, CarbonImmutable $tot): array
    {
        $versie = config('dashed-ecommerce-rendabiliteit.koppelingen.meta_versie');
        $url = "https://graph.facebook.com/{$versie}/act_{$this->account()}/insights";
        $parameters = [
            'level' => 'account',
            'fields' => 'spend,account_currency',
            'time_increment' => 1,
            'limit' => 100,
            'time_range' => json_encode(['since' => $van->toDateString(), 'until' => $tot->toDateString()]),
        ];
        $uitgaven = [];

        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            $antwoord = $this->verzoek(fn () => Http::timeout(30)
                ->withToken((string) KoppelingInstellingen::geheim('meta_token'))
                ->acceptJson()
                ->get($url, $parameters));

            foreach ($antwoord->json('data') ?? [] as $rij) {
                $this->controleerValuta($rij['account_currency'] ?? null);
                $dag = $rij['date_start'];
                $uitgaven[$dag] = ($uitgaven[$dag] ?? 0.0) + (float) ($rij['spend'] ?? 0);
            }

            $volgende = $antwoord->json('paging.cursors.after');

            if (! $antwoord->json('paging.next') || ! $volgende) {
                return $uitgaven;
            }

            $parameters['after'] = $volgende;
        }

        throw new KoppelingFout('Meta gaf te veel pagina\'s terug.');
    }

    private function account(): string
    {
        return preg_replace('/\D/', '', (string) KoppelingInstellingen::waarde('meta_account'));
    }

    private function foutUit(Response $antwoord): ?string
    {
        return $antwoord->json('error.message');
    }
}
