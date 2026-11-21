<?php

$primaryRootDomain = strtolower(trim((string) env('TENANT_PRIMARY_ROOT_DOMAIN', 'fusionerp.systems')));
$legacyRootDomain = strtolower(trim((string) env('TENANT_ROOT_DOMAIN', 'professionalacademyedu.com')));
$configuredRootDomains = array_map(
    'strtolower',
    array_map('trim', explode(',', (string) env('TENANT_ROOT_DOMAINS', 'fusionerp.systems,professionalacademyedu.com')))
);
$tenantRootDomains = array_values(array_unique(array_filter(array_merge(
    [$primaryRootDomain, $legacyRootDomain],
    $configuredRootDomains
))));

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

    'allowed_origins_patterns' => array_map(
        fn (string $domain): string => '#^https?://([a-z0-9-]+\\.)?' . preg_quote($domain, '#') . '(:\\d+)?$#i',
        $tenantRootDomains
    ),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
