<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen;

use Dashed\DashedEcommerceRendabiliteit\Instellingen\KoppelingInstellingen;

/**
 * Een prompt per koppeling om in Chrome aan een AI-agent (Claude in Chrome)
 * te geven: die regelt de inloggegevens bij het platform en vult ze op de
 * koppelingenpagina in. Zuiver: krijgt de stand mee, leest zelf niets.
 * Geheimen staan er nooit in, alleen of ze ingevuld zijn.
 */
class KoppelingPrompt
{
    /** Velden per koppeling: veld => [label, geheim]. */
    public const VELDEN = [
        KoppelingInstellingen::META => [
            'meta_account' => ['Advertentieaccount-ID', false],
            'meta_token' => ['Toegangstoken', true],
        ],
        KoppelingInstellingen::GOOGLE => [
            'google_customer_id' => ['Klant-ID', false],
            'google_login_customer_id' => ['Manageraccount-ID', false],
            'google_client_id' => ['OAuth client-ID', false],
            'google_client_secret' => ['OAuth client secret', true],
            'google_refresh_token' => ['Refresh token', true],
            'google_developer_token' => ['Developer token', true],
        ],
        KoppelingInstellingen::BOL => [
            'bol_client_id' => ['Advertiser-ID', false],
            'bol_client_secret' => ['Advertiser secret', true],
        ],
    ];

    private const NAMEN = [
        KoppelingInstellingen::META => 'Meta',
        KoppelingInstellingen::GOOGLE => 'Google Ads',
        KoppelingInstellingen::BOL => 'Bol',
    ];

    /**
     * @param  array<string, bool>  $ingevuld  veld => of er al een waarde staat
     * @param  array{at?: string, gelukt?: bool, fout?: ?string}|null  $stand
     */
    public static function voor(string $koppeling, string $paginaUrl, array $ingevuld, ?array $stand, bool $aan): string
    {
        $naam = self::NAMEN[$koppeling] ?? $koppeling;

        $regels = [
            "Je helpt me de {$naam}-koppeling van mijn webshop werkend te krijgen. De webshop haalt elke nacht de advertentie-uitgaven per dag op via de {$naam}-API.",
            '',
            "Koppelingenpagina: {$paginaUrl} (sectie \"{$naam}\").",
            '',
            'Huidige stand:',
            '- Elke nacht ophalen: '.($aan ? 'aan' : 'uit'),
        ];

        foreach (self::VELDEN[$koppeling] ?? [] as $veld => [$label, $geheim]) {
            $regels[] = "- {$label}: ".(($ingevuld[$veld] ?? false) ? 'ingevuld' : 'leeg').($geheim ? ' (geheim, wordt niet teruggetoond)' : '');
        }

        $regels[] = '- Laatste ophaalpoging: '.self::standTekst($stand);
        $regels[] = '';
        $regels[] = 'Wat ik wil:';
        $regels[] = '1. Zoek uit wat er mis is of ontbreekt (kijk naar de foutmelding hierboven als die er is).';
        $regels[] = "2. Regel de ontbrekende of foute gegevens bij {$naam}, volgens de stappen hieronder.";
        $regels[] = "3. Vul ze in op de koppelingenpagina en klik in de sectie \"{$naam}\" op \"Controleer verbinding\". Die test de gegevens zonder iets op te slaan.";
        $regels[] = '4. Werkt het niet, lees de melding, los het op en controleer opnieuw. Werkt het wel, zet "Elke nacht ophalen" aan, klik op Opslaan en daarna op "Nu ophalen (7 dagen)".';
        $regels[] = '';
        $regels[] = "Stappen bij {$naam}:";

        foreach (self::stappen($koppeling) as $i => $stap) {
            $regels[] = ($i + 1).'. '.$stap;
        }

        $regels[] = '';
        $regels[] = 'Regels:';
        $regels[] = '- Alleen leesrechten aanvragen. Verander niets aan campagnes, budgetten, betaalgegevens of gebruikers behalve wat hiervoor nodig is.';
        $regels[] = '- Zet geheimen (tokens, secrets) nooit in de chat; plak ze alleen in de velden op de koppelingenpagina.';
        $regels[] = '- Een geheim veld dat al "Opgeslagen" toont en klopt, leeg laten: leeg betekent ongewijzigd.';
        $regels[] = '- Vraag het mij bij een inlog, tweestapsverificatie, betaling, of als je twijfelt welk account het juiste is.';
        $regels[] = '- Meld aan het eind kort wat je hebt gedaan en wat nog open staat.';

        return implode("\n", $regels);
    }

    /** @return list<string> */
    public static function stappen(string $koppeling): array
    {
        return match ($koppeling) {
            KoppelingInstellingen::META => [
                'Ga naar business.facebook.com → Instellingen → Accounts → Advertentieaccounts en noteer het ID van het advertentieaccount van de webshop (cijfers; "act_" ervoor mag).',
                'Ga naar Instellingen → Gebruikers → Systeemgebruikers. Gebruik een bestaande systeemgebruiker of maak er een (rol Medewerker).',
                'Wijs die systeemgebruiker het advertentieaccount toe met minimaal de rechten om prestaties te bekijken.',
                'Genereer een token voor die systeemgebruiker met de toestemming ads_read (een app van het bedrijf kiezen; vervaldatum "Nooit" als dat kan).',
                'Vul Advertentieaccount-ID en Toegangstoken in op de koppelingenpagina.',
                'Bij de fout "status 190" of "token" is het token verlopen of ingetrokken: genereer een nieuw. Bij "status 10/200/275" ontbreekt ads_read of de toewijzing van het account.',
            ],
            KoppelingInstellingen::GOOGLE => [
                'Ga naar ads.google.com en noteer het klant-ID (123-456-7890) van het account met de campagnes. Zit het onder een manageraccount (MCC), noteer dan ook het ID van dat manageraccount.',
                'Developer token: in het manageraccount → Beheer (Tools) → API-centrum. Geen manageraccount? Maak er een aan en koppel het advertentieaccount. Basic access is genoeg; een token met alleen "test account access" werkt niet op echte accounts.',
                'Ga naar console.cloud.google.com, kies of maak een project en zet "Google Ads API" aan (API\'s en services → Bibliotheek).',
                'Stel het OAuth-toestemmingsscherm in (extern, publiceer de app naar "In productie", anders verloopt de refresh token na 7 dagen).',
                'Maak onder Inloggegevens een OAuth-client-ID van het type "Webapplicatie". Noteer client-ID en client secret.',
                'Zet in die OAuth-client bij "Geautoriseerde omleidings-URI\'s" de URI die op de koppelingenpagina onder "OAuth client-ID" staat. Vul client-ID en client secret in, sla op en klik in de sectie Google Ads op "Koppel met Google". Laat mij inloggen en toestemming geven; de webshop slaat de refresh token zelf op.',
                'Vul Klant-ID, eventueel Manageraccount-ID en de developer token in op de koppelingenpagina.',
                'Bij "invalid_grant" is de refresh token verlopen of ingetrokken: klik opnieuw op "Koppel met Google". Bij "redirect_uri_mismatch" staat de omleidings-URI niet (precies) in de OAuth-client. Bij "DEVELOPER_TOKEN_NOT_APPROVED" heeft de developer token alleen testtoegang. Bij "USER_PERMISSION_DENIED" ontbreekt of klopt het Manageraccount-ID niet.',
            ],
            KoppelingInstellingen::BOL => [
                'Ga naar partner.bol.com → Instellingen → API-instellingen (of het Partnerplatform van Bol) en kies de Advertising API, niet de Retailer API.',
                'Maak daar client credentials (client-ID en secret) voor de Advertising API aan, of gebruik bestaande.',
                'Vul het client-ID in als Advertiser-ID en het secret als Advertiser secret op de koppelingenpagina.',
                'Bij "status 401" of "geen toegangstoken" kloppen de credentials niet of zijn het die van de Retailer API. Bij "status 403" heeft het account geen toegang tot Sponsored Products-rapportage. Bol levert hoogstens 30 dagen terug.',
            ],
            default => [],
        };
    }

    private static function standTekst(?array $stand): string
    {
        if (! $stand) {
            return 'nog nooit opgehaald';
        }

        $moment = $stand['at'] ?? 'onbekend moment';

        return ($stand['gelukt'] ?? false)
            ? "gelukt ({$moment})"
            : "mislukt ({$moment}), melding: ".($stand['fout'] ?: 'geen melding');
    }
}
