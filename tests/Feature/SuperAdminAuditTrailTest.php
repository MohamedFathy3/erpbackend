<?php

namespace Tests\Feature;

use App\Http\Controllers\AuditTrailController;
use App\Http\Middleware\AuditTrailMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SuperAdminAuditTrailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('tenants');
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
        });
        $migration = require base_path('database/migrations/2026_10_16_180000_create_audit_events_table.php');
        $migration->up();
    }

    public function test_tenant_admin_cannot_read_the_platform_audit_trail(): void
    {
        $request = Request::create('/api/super-admin/audit-trail', 'GET');
        $request->setUserResolver(static fn () => (object) ['super_admin' => false]);

        try {
            app(AuditTrailController::class)->index($request);
            $this->fail('Expected a 403 response for a non-super-admin.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_super_admin_can_filter_the_audit_trail_to_one_tenant(): void
    {
        $firstTenantId = DB::table('tenants')->insertGetId(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $secondTenantId = DB::table('tenants')->insertGetId(['name' => 'Tenant B', 'slug' => 'tenant-b']);
        foreach ([[$firstTenantId, 'Tenant A'], [$secondTenantId, 'Tenant B']] as [$tenantId, $tenantName]) {
            DB::table('audit_events')->insert([
                'tenant_id' => $tenantId,
                'tenant_name' => $tenantName,
                'actor_id' => 77,
                'actor_type' => 'Admin',
                'actor_name' => 'Tenant User',
                'event_type' => 'update',
                'outcome' => 'success',
                'http_method' => 'PATCH',
                'request_path' => 'api/products/4',
                'status_code' => 200,
                'duration_ms' => 35,
                'created_at' => now(),
            ]);
        }
        DB::table('audit_events')->insert([
            'tenant_id' => $firstTenantId,
            'tenant_name' => 'Tenant A',
            'actor_id' => 77,
            'actor_type' => 'Admin',
            'actor_name' => 'Tenant User',
            'event_type' => 'create',
            'outcome' => 'failed',
            'http_method' => 'POST',
            'request_path' => 'api/sales-invoices/store',
            'status_code' => 500,
            'duration_ms' => 35,
            'request_data' => json_encode(['invoice_number' => 'INV-20', 'total_amount' => 99]),
            'created_at' => now(),
        ]);

        $request = Request::create('/api/super-admin/audit-trail?tenant_id=' . $firstTenantId, 'GET');
        $request->setUserResolver(static fn () => (object) ['super_admin' => true]);
        $response = app(AuditTrailController::class)->index($request);
        $data = $response->getData(true);

        $this->assertSame(2, $data['summary']['total']);
        $this->assertCount(2, $data['data']['data']);
        $this->assertSame($firstTenantId, $data['data']['data'][0]['tenant_id']);
        $this->assertTrue($data['data']['data'][0]['retryable']);
    }

    public function test_failed_request_is_logged_with_password_redacted(): void
    {
        $tenantId = DB::table('tenants')->insertGetId(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $actor = new class($tenantId) {
            public int $id = 42;
            public string $name = 'Test Admin';
            public string $email = 'admin@example.test';
            public int $branch_id = 3;
            public bool $super_admin = false;
            public function __construct(public int $tenant_id) {}
            public function getAuthIdentifier(): int { return $this->id; }
        };
        $request = Request::create('/api/sales-invoices/store', 'POST', [
            'invoice_number' => 'INV-100',
            'password' => 'never-save-this',
            'total_amount' => 125.50,
        ]);
        $request->setUserResolver(static fn () => $actor);

        $response = app(AuditTrailMiddleware::class)->handle(
            $request,
            static fn () => response()->json([
                'message' => 'Supplier account is inactive',
                'errors' => ['supplier_id' => ['Supplier account is inactive']],
            ], 422),
        );
        $event = DB::table('audit_events')->first();
        $requestData = json_decode($event->request_data, true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('failed', $event->outcome);
        $this->assertStringContainsString('Supplier account is inactive', $event->error_summary);
        $this->assertSame(['Supplier account is inactive'], json_decode($event->error_summary, true)['errors']['supplier_id']);
        $this->assertSame('[REDACTED]', $requestData['password']);
        $this->assertSame($tenantId, $event->tenant_id);
    }

    public function test_application_level_failure_with_http_200_is_still_recorded_as_failed(): void
    {
        $tenantId = DB::table('tenants')->insertGetId(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $actor = new class($tenantId) {
            public int $id = 43;
            public string $name = 'Test Admin';
            public string $email = 'admin@example.test';
            public ?int $branch_id = null;
            public bool $super_admin = false;
            public function __construct(public int $tenant_id) {}
            public function getAuthIdentifier(): int { return $this->id; }
        };
        $request = Request::create('/api/purchase-invoices', 'POST', ['invoice_number' => 'PO-1']);
        $request->setUserResolver(static fn () => $actor);

        app(AuditTrailMiddleware::class)->handle(
            $request,
            static fn () => response()->json(['success' => false, 'message' => 'Supplier account is inactive'], 200),
        );
        $event = DB::table('audit_events')->first();

        $this->assertSame('failed', $event->outcome);
        $this->assertSame(200, $event->status_code);
        $this->assertSame('Supplier account is inactive', $event->error_summary);
    }
}
