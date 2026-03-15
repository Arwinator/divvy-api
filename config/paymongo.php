<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PayMongo API Keys
    |--------------------------------------------------------------------------
    |
    | Your PayMongo API keys for processing payments. You can find these
    | in your PayMongo dashboard at https://dashboard.paymongo.com/developers
    |
    */

    'public_key' => env('PAYMONGO_PUBLIC_KEY'),
    'secret_key' => env('PAYMONGO_SECRET_KEY'),
    'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | PayMongo API Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for PayMongo API requests.
    |
    */

    'base_url' => 'https://api.paymongo.com/v1',

    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    |
    | Supported payment methods for the application.
    |
    */

    'payment_methods' => [
        'gcash',
        'paymaya',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Redirect URLs
    |--------------------------------------------------------------------------
    |
    | URLs for payment success and failure redirects.
    |
    */

    'redirect_urls' => [
        'success' => env('APP_URL') . '/payment/success',
        'failed' => env('APP_URL') . '/payment/failed',
    ],

];
