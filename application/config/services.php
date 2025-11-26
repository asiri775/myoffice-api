<?php

return [
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI', 'http://api.mybackpocket.co/api/oauth/google/callback'),
    ],

    'facebook' => [
        'client_id'     => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect'      => env('FACEBOOK_REDIRECT_URI', 'https://your-frontend.example.com/auth/facebook/callback'),
    ],
    'twoco' => [
        'sandbox' => (bool) env('TWoco_SANDBOX', true),
        // optional secret used in your web hash compare
        'hash_prefix' => env('TWoco_HASH_PREFIX', 'name205CAD2001'),
    ],
];
