<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ResolveTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_tenant_header_takes_precedence_over_api_subdomain(): void
    {
        config(['tenancy.root_domain' => 'professionalacademyedu.com']);
        $tenant = Tenant::create(['name' => 'ACSA', 'slug' => 'acsa']);
        $request = Request::create('https://protect-plus-2.professionalacademyedu.com/api/me/enabled-modules', 'GET');
        $request->headers->set('X-Tenant-Slug', 'acsa');
        $request->setUserResolver(fn () => (object) ['tenant_id' => $tenant->id, 'super_admin' => false]);

        $response = app(ResolveTenant::class)->handle($request, fn () => response()->json([
            'tenant_id' => app('currentTenantId'),
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($tenant->id, $response->getData(true)['tenant_id']);
    }

    public function test_tenant_header_cannot_switch_an_authenticated_user_to_another_workspace(): void
    {
        config(['tenancy.root_domain' => 'professionalacademyedu.com']);
        $accountTenant = Tenant::create(['name' => 'Account tenant', 'slug' => 'account-tenant']);
        Tenant::create(['name' => 'Other tenant', 'slug' => 'other-tenant']);
        $request = Request::create('https://protect-plus-2.professionalacademyedu.com/api/me/permissions', 'GET');
        $request->headers->set('X-Tenant-Slug', 'other-tenant');
        $request->setUserResolver(fn () => (object) ['tenant_id' => $accountTenant->id, 'super_admin' => false]);

        $response = app(ResolveTenant::class)->handle($request, fn () => response()->noContent());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('tenant_mismatch', $response->getData(true)['code']);
    }
}
