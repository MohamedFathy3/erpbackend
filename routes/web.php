<?php

use App\Http\Controllers\AdmsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// ZKTeco terminals from all SaaS tenants use this central endpoint. The
// controller resolves the tenant by the registered device serial number.
Route::middleware(['throttle:240,1'])->group(function (): void {
    Route::match(['GET', 'POST'], '/iclock/registry', [AdmsController::class, 'registry']);
    Route::match(['GET', 'POST'], '/iclock/cdata', [AdmsController::class, 'cdata']);
    Route::get('/iclock/getrequest', [AdmsController::class, 'getrequest']);
    Route::post('/iclock/devicecmd', [AdmsController::class, 'devicecmd']);
});

require __DIR__.'/auth.php';
