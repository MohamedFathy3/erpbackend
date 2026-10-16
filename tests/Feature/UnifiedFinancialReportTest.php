<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class UnifiedFinancialReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_includes_employees_without_requiring_an_active_column(): void
    {
        $tenant = Tenant::create(['name' => 'Report tenant', 'slug' => 'report-test']);
        $role = Role::create(['name' => 'Employee']);
        $employee = Employee::create([
            'name' => 'Report employee',
            'role_id' => $role->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($employee);
        $request = Request::create('/api/reports/unified-financial', 'GET', [
            'from' => '2026-09-07',
            'to' => '2026-10-07',
        ]);
        $request->setUserResolver(fn () => $employee);

        $response = app(DashboardController::class)->unifiedFinancialReport($request);
        $data = $response->getData(true)['data'];

        $this->assertSame(1, $data['summary']['employee_count']);
        $this->assertSame($employee->id, $data['employees'][0]['id']);
        $this->assertSame([], $data['sales_invoices']);
        $this->assertSame([], $data['pos_invoices']);
        $this->assertSame([], $data['purchase_invoices']);
        $this->assertSame([], $data['purchase_payments']);
        $this->assertSame([], $data['expenses']);
        $this->assertSame([], $data['treasury_transactions']);
        $this->assertSame(0.0, $data['summary']['treasury_out']);
    }
}
