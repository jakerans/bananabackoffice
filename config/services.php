<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Address lookup. The token stays on the server and is never sent to a browser.
    // Left empty, lookup is switched off and addresses are typed by hand.
    'mapbox' => [
        'token' => env('MAPBOX_ACCESS_TOKEN'),
    ],

    // QuickBooks Online (slice 28, decision 189): one app registered with Intuit, connected
    // to each Location's own company. Its keys stay on the server. Left empty, connecting
    // is switched off. "sandbox" reads Intuit's test companies; "production" real ones.
    'quickbooks' => [
        'client_id' => env('QUICKBOOKS_CLIENT_ID'),
        'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
        'environment' => env('QUICKBOOKS_ENVIRONMENT', 'sandbox'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
