<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:7000',
        'http://localhost:7001',
        'http://127.0.0.1:7000',
        'http://127.0.0.1:7001',
    ],

    'allowed_origins_patterns' => [
        '#^https?://([a-z0-9-]+\.)?'
            . preg_quote(env('TENANT_ROOT_DOMAIN', 'example.com'), '#')
            . '(:\d+)?$#i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];