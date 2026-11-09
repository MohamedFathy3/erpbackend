<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckModuleEnabled;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CheckModuleEnabledTest extends TestCase
{
    public function test_sales_representative_login_is_available_without_an_existing_session(): void
    {
        $request = Request::create('/api/sales-representative/login', 'POST');
        $response = app(CheckModuleEnabled::class)->handle(
            $request,
            fn (): Response => response('login route reached'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('login route reached', $response->getContent());
    }
}
