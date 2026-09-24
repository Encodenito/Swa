<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PayMongo
    |--------------------------------------------------------------------------
    |
    | Test mode only while building. Keys come from the environment; never
    | commit or log them. The docs (docs.paymongo.com) document checkout
    | sessions under /v1, so the path is kept here in one place.
    |
    */

    'secret_key' => env('PAYMONGO_SECRET_KEY'),

    'public_key' => env('PAYMONGO_PUBLIC_KEY'),

    'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),

    // Selects which signature in the Paymongo-Signature header is checked: "li" when true, "te" when false.
    'livemode' => (bool) env('PAYMONGO_LIVEMODE', false),

    'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com'),

    'checkout_path' => '/v1/checkout_sessions',

    'refunds_path' => '/v1/refunds',

    'payment_method_types' => ['gcash', 'card'],

    // Hosts a checkout_url may point to before a customer is redirected there.
    'checkout_hosts' => ['checkout.paymongo.com'],

    // Seconds for each HTTP call to PayMongo.
    'timeout' => 15,

    // Webhooks with a signature timestamp older than this many seconds are rejected (replay protection).
    'webhook_tolerance' => 300,

];
