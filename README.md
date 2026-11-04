# Dashed Ecommerce Rendabiliteit

Rendabiliteit voor Dashed-webshops: omzet, kosten, marge en advertentie-uitgaven per maand, kanaal en productgroep.

Onderdelen (Filament, navigatiegroep Rendabiliteit, permissie `view_statistics`):

- Rapport met aandachtspunten en PDF-export (per maand, periode, vergelijken, progressie)
- Advertenties (dashboard, per week/maand/kwartaal/jaar), Advertentie-uitgaven (importeren, ads-exports inlezen) en een API
- Tarieven (betaal-, verzend- en verpakkingskosten), Vaste lasten
- Verzendfacturen (uitlezen met Claude, correctiefactor op de geschatte verzendkosten)
- Marges en adviesprijs (Kostprijzen)
- Inkoopfacturen: uitlezen (pdf, csv, xlsx), koppelen op EAN of SKU en toepassen als `purchase_price`

## Installatie

```bash
composer require dashed/dashed-ecommerce-rendabiliteit
php artisan migrate
```

Registreer de plugin in het Filament-panel:

```php
use Dashed\DashedEcommerceRendabiliteit\DashedEcommerceRendabiliteitPlugin;

$panel->plugin(new DashedEcommerceRendabiliteitPlugin());
```

Zet in `.env` een token voor de advertentie-API (leeg = API dicht):

```
ADVERTENTIE_API_TOKEN=
```

`POST /api/advertentie-uitgaven` met `{"rows": [{"datum": "2026-10-29", "platform": "meta", "bedrag": 24.10}]}` en
`GET /api/advertentie-uitgaven?van=&tot=`, met `Authorization: Bearer <token>`.

Config (`config/dashed-ecommerce-rendabiliteit.php`): `vervoerders`, `api_token`, `bestandsprefix`.

De migratie maakt de tabellen aan als ze er nog niet zijn en vult geen tarieven: vul betaal-, verzend- en verpakkingskosten in
onder Rendabiliteit → Tarieven (zonder tarief rekent het rapport met €0 en toont een waarschuwing).
`Startwaarden::zet()` is alleen een hulpklasse (o.a. voor tests) en wordt niet automatisch aangeroepen.

## Standaardgedrag

- Kostprijsbron: `InkoopprijsBron`; de kostprijs is `purchase_price` van het product, zonder `purchase_price` is er geen kostprijs (wel een waarschuwing in het rapport).
- Maatsleutel: `''` (geen maatverdeling per productgroep).
- Vervoerders komen uit de config; alleen vervoerders waarvan de labeltabel bestaat doen mee.
- Geen uitgesloten productgroepen; cadeaubonnen tellen generiek niet mee.
- Rapportcache: schema 12.

## Haken (`rendabiliteit()`)

Een shop breidt het package uit in een service provider (`boot`):

```php
rendabiliteit()->kostprijsBron(KostprijsBron $bron);                          // waar de kostprijs vandaan komt
rendabiliteit()->sluitGroepenUit(Closure(): array<int>);                       // groep-id's die niet meetellen in kostprijs en marge
rendabiliteit()->kostprijsLink(Closure(int $groepId, string $sleutel): ?array); // ['url', 'label'], optioneel 'reden' en 'maat'
rendabiliteit()->maatsleutel(Closure(Product): string);                        // maatsleutel per product
rendabiliteit()->maatsleutels(Closure(iterable): array<int, string>);          // idem in batch (sneller)
rendabiliteit()->extraSignalen(Closure(Collection): list<array>);              // bv. ['soort' => 'verwacht_duurder', 'product_id' => 12]
```

`KostprijsBron` (`Contracts\KostprijsBron`) levert per product een `Kostprijs\Kostprijs` (`totaal` en `opbouw`) of `null`.

Voorbeeld: Lovora (`app/Providers/RendabiliteitServiceProvider.php`) registreert een `PrintprofielBron` (kostprijs uit gram,
printtijd en handwerk, via een scoped `Kostprijsberekenaar`), de uitgesloten groepen (outlet, wenskaart, cadeaubon),
een `kostprijsLink` naar het printprofiel, een maatsleutel uit de variantfilters en het dual-color signaal.
Wie na een wijziging aan de bron de rapporten wil verversen, roept `RendabiliteitCache::wis()` aan.
