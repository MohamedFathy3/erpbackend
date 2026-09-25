<?php

$providers = [
    App\Providers\AppServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
];

// Telescope is a development dependency. Do not boot its provider when
// production is installed with `composer install --no-dev`.
if (class_exists('Laravel\\Telescope\\TelescopeApplicationServiceProvider')) {
    $providers[] = App\Providers\TelescopeServiceProvider::class;
}

return $providers;
