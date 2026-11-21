<?php

$primaryRootDomain = strtolower(trim((string) env('TENANT_PRIMARY_ROOT_DOMAIN', 'fusionerp.systems')));
$legacyRootDomain = strtolower(trim((string) env('TENANT_ROOT_DOMAIN', 'professionalacademyedu.com')));
$configuredRootDomains = array_map(
    'strtolower',
    array_map('trim', explode(',', (string) env('TENANT_ROOT_DOMAINS', 'fusionerp.systems,professionalacademyedu.com')))
);
$rootDomains = array_values(array_unique(array_filter(array_merge(
    [$primaryRootDomain, $legacyRootDomain],
    $configuredRootDomains
))));

$centralDomains = [];
foreach ($rootDomains as $rootDomain) {
    $centralDomains[] = $rootDomain;
    $centralDomains[] = 'www.' . $rootDomain;
    $centralDomains[] = 'admin.' . $rootDomain;
}
$configuredCentralDomains = array_map(
    'strtolower',
    array_map('trim', explode(',', (string) env('CENTRAL_DOMAINS', 'test.injazyemen.cloud,injazyemen.cloud')))
);
$centralDomains = array_values(array_unique(array_filter(array_merge(
    $centralDomains,
    $configuredCentralDomains
))));

return [
    'root_domain' => $primaryRootDomain,
    'root_domains' => $rootDomains,
    'central_domains' => implode(',', $centralDomains),
];
