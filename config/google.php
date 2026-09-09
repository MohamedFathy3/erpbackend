<?php

return [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/api/integrations/google/callback'),
    'frontend_redirect_uri' => env('GOOGLE_FRONTEND_REDIRECT_URI', env('APP_URL') . '/google-integrations'),
    'scopes' => [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/drive.file',
    ],
];
