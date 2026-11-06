# Changelog

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
