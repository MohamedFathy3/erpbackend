<?php

return [
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
    'timeout' => env('WHATSAPP_TIMEOUT', 15),
];
