<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lamstone / clinic pharmacy integration
    |--------------------------------------------------------------------------
    |
    | Product catalog and prescription push for the linked Lamstone pharmacy store.
    | Keep the API key server-side only — never expose it to the frontend.
    |
    */

    'base_url' => rtrim(env('PHARMACY_API_BASE_URL', 'https://admin.lamstonehealthcare.com'), '/'),
    'api_key' => env('PHARMACY_API_KEY'),
    'store_code' => env('PHARMACY_STORE_CODE', 'STR-008'),

    /** When true and credentials are set, /medicines is served from the clinic catalog. */
    'enabled' => env('PHARMACY_API_ENABLED', true),

];
