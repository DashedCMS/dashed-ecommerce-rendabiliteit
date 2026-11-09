<?php

return [
    /*
     * Vervoerders voor verzendlabels en verzendfacturen. Alleen vervoerders
     * waarvan de tabel bestaat doen mee. 'naam' is de waarde in
     * verzendfacturen.vervoerder, 'relatie' de relatie op de order.
     */
    'vervoerders' => [
        'veloyd' => ['naam' => 'Veloyd', 'tabel' => 'dashed__order_veloyd', 'relatie' => 'veloydOrders'],
        'myparcel' => ['naam' => 'MyParcel', 'tabel' => 'dashed__order_my_parcel', 'relatie' => 'myParcelOrders'],
        'sendy' => ['naam' => 'Sendy', 'tabel' => 'dashed__order_sendy', 'relatie' => 'sendyOrders'],
    ],

    /* Bearer-token van de advertentie-API (/api/advertentie-uitgaven); leeg = API dicht. */
    'api_token' => env('ADVERTENTIE_API_TOKEN'),

    /* API-versies van de advertentiekoppelingen; Google stopt een versie na ± 1 jaar. */
    'koppelingen' => [
        'meta_versie' => env('RENDABILITEIT_META_API_VERSIE', 'v27.0'),
        'google_versie' => env('RENDABILITEIT_GOOGLE_API_VERSIE', 'v26'),
        /* Accept-header voor de Bol Advertising API; leeg = de kandidaten om de beurt proberen (Bol documenteert hem niet). */
        'bol_accept' => env('RENDABILITEIT_BOL_ACCEPT'),
    ],

    /* Inkoopfacturen-resource registreren; false voor shops waar de kostprijs niet uit purchase_price komt. */
    'inkoopfacturen' => true,

    /* Voorvoegsel van de PDF-bestandsnaam; leeg = de slug van app.name. */
    'bestandsprefix' => null,
];
