<?php

return [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/api/integrations/google/callback'),
    'auth_redirect_uri' => env('GOOGLE_AUTH_REDIRECT_URI', env('APP_URL') . '/api/auth/google/callback'),
    'frontend_redirect_uri' => env('GOOGLE_FRONTEND_REDIRECT_URI', env('APP_URL') . '/google-integrations'),
    'frontend_auth_redirect_uri' => env('GOOGLE_FRONTEND_AUTH_REDIRECT_URI', env('APP_URL') . '/auth'),
    'scopes' => [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/drive.file',
    ],
];
