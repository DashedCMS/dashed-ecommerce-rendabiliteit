# Changelog

## v4.4.0

- Advertentiekoppelingen: actie "Geschiedenis ophalen" (startdatum, standaard de eerste order; optioneel één kanaal). Meta gaat hoogstens 37 maanden terug en haalt lange periodes op in blokken van 90 dagen; Bol blijft op 30 dagen, Google onbeperkt. Ook via `rendabiliteit:haal-advertenties --van=`.

## v4.3.1

- Bol-koppeling: de Advertising API weigerde `Accept: application/json` met een 406. Nu worden vendor-types op volgorde geprobeerd (`vnd.advertiser.v11`, `vnd.retailer.v11`, `vnd.advertiser.v10`, `json`) en wordt de werkende onthouden; vast te zetten met `RENDABILITEIT_BOL_ACCEPT`.

## v4.3.0

- Advertentiekoppelingen: knop "Controleer verbinding" per kanaal. Bewaart het formulier, vraagt gisteren op (ook als nachtelijk ophalen uit staat) en meldt het bedrag of de fout; er wordt niets opgeslagen. `AdvertentieKoppelingen::controleer()` en `lege()`. De AI-prompt verwijst ernaar.

## v4.2.0

- Advertentiekoppelingen: per kanaal een kopieerbare prompt voor Claude in Chrome met de huidige stand (welke velden leeg zijn, laatste fout) en de stappen om de inloggegevens te regelen en in te vullen. Geheimen staan er nooit in.

## v4.1.0

- Advertentiekoppelingen: Meta, Google Ads en Bol halen elke nacht de uitgaven per dag op (`rendabiliteit:haal-advertenties`, pagina Rendabiliteit → Advertentiekoppelingen). Staan standaard uit.
- `rendabiliteit:vul-orderregel-kostprijzen` draait elke nacht, zodat orderregels een kostprijs krijgen zodra een product later een inkoopprijs krijgt.

## v4.0.0

- Eerste release: rendabiliteit uit Lovora als Composer-package.
- Rapport (maandcijfers, kanalen, productgroepen, aandachtspunten, PDF-export), advertentie-uitgaven met dashboard en API, tarieven, vaste lasten, verzendfacturen, marges en adviesprijs.
- Inkoopfacturen inlezen en toepassen als inkoopprijs; advertentie-exports (Google, Meta, Bol) inlezen.
- Uitbreidingshaken via `rendabiliteit()`: kostprijsbron, uitgesloten groepen, kostprijslink, maatsleutel(s) en extra signalen.
- Standaard kostprijsbron: `purchase_price` van het product (`InkoopprijsBron`).
- Rapportcache-schema 12, cacheprefix `dashed-ecommerce-rendabiliteit.`.
