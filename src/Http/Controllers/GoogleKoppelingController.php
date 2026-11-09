<?php

namespace Dashed\DashedEcommerceRendabiliteit\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Client\ConnectionException;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\KoppelingInstellingen;
use Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit\AdvertentieKoppelingenPage;

/**
 * "Koppel met Google": OAuth-toestemming in het eigen account, waarna de refresh token
 * versleuteld wordt opgeslagen. Vervangt het omweggetje via de OAuth Playground.
 */
class GoogleKoppelingController
{
    public const SCOPE = 'https://www.googleapis.com/auth/adwords';

    private const SESSIE = 'rendabiliteit.google_oauth_state';

    public static function terugUrl(): string
    {
        return route('rendabiliteit.google.terug');
    }

    public function koppelen(Request $request): RedirectResponse
    {
        $this->magHet();

        $clientId = KoppelingInstellingen::waarde('google_client_id');

        if ($clientId === null || KoppelingInstellingen::geheim('google_client_secret') === null) {
            return $this->terug('Vul eerst het OAuth client-ID en de client secret in en sla op.', false);
        }

        $state = Str::random(40);
        $request->session()->put(self::SESSIE, $state);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::terugUrl(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            // Zonder consent geeft Google bij een tweede keer geen refresh token.
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]));
    }

    public function terugVanGoogle(Request $request): RedirectResponse
    {
        $this->magHet();

        $verwacht = $request->session()->pull(self::SESSIE);

        if (! $verwacht || ! hash_equals($verwacht, (string) $request->query('state'))) {
            return $this->terug('De koppeling is verlopen of ongeldig. Probeer het opnieuw.', false);
        }

        if ($request->filled('error')) {
            return $this->terug('Google gaf geen toestemming: '.$request->query('error').'.', false);
        }

        try {
            $antwoord = Http::timeout(30)->asForm()->acceptJson()->post('https://oauth2.googleapis.com/token', [
                'code' => (string) $request->query('code'),
                'client_id' => KoppelingInstellingen::waarde('google_client_id'),
                'client_secret' => KoppelingInstellingen::geheim('google_client_secret'),
                'redirect_uri' => self::terugUrl(),
                'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException) {
            return $this->terug('Google is niet bereikbaar. Probeer het opnieuw.', false);
        }

        $token = $antwoord->json('refresh_token');

        if (! $antwoord->successful() || ! $token) {
            $melding = $antwoord->json('error_description') ?? $antwoord->json('error') ?? "status {$antwoord->status()}";

            return $this->terug("Google gaf geen refresh token: {$melding}.", false);
        }

        KoppelingInstellingen::zetGeheim('google_refresh_token', $token);

        return $this->terug('Gekoppeld met Google: de refresh token is opgeslagen. Klik nu op "Controleer verbinding".', true);
    }

    private function magHet(): void
    {
        abort_unless(auth()->user()?->can('view_statistics'), 403);
    }

    private function terug(string $melding, bool $gelukt): RedirectResponse
    {
        return redirect(AdvertentieKoppelingenPage::getUrl())->with('rendabiliteit.google_koppeling', ['melding' => $melding, 'gelukt' => $gelukt]);
    }
}
