<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AnyCable Driver Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may specify the configuration options for the AnyCable
    | broadcaster driver.
    |
    */

    'driver' => 'anycable',

    // Currently only 'pusher' protocol is supported
    'protocol' => 'pusher',

    // URL to broadcast messages to the AnyCable server
    'http_broadcast_url' => env('ANYCABLE_HTTP_BROADCAST_URL', null),

    // Secret key for signing authentication tokens for private channels
    'secret' => env('ANYCABLE_SECRET', null),

    // Key to sign streams
    'streams_key' => env('ANYCABLE_STREAMS_KEY', null),

    // Key for HTTP broadcasting API (if your AnyCable server requires authentication)
    'broadcast_key' => env('ANYCABLE_BROADCAST_KEY', null),

    // Timeout for HTTP requests to the AnyCable server
    'timeout' => env('ANYCABLE_BROADCAST_TIMEOUT', 5),

    // Server configuration (for php artisan anycable:server)
    'server' => [
        'secret' => env('ANYCABLE_SECRET', null),
        'pusher_app_id' => env('ANYCABLE_PUSHER_APP_ID', env('REVERB_APP_ID')),
        'pusher_app_key' => env('ANYCABLE_PUSHER_APP_KEY', env('REVERB_APP_KEY')),
        'pusher_secret' => env('ANYCABLE_PUSHER_SECRET', env('REVERB_APP_SECRET')),
        'broadcast_adapter' => env('ANYCABLE_BROADCAST_ADAPTER', 'http'),
        'presets' => env('ANYCABLE_PRESETS', 'broker'),
    ],
];
