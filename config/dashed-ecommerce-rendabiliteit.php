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
];
