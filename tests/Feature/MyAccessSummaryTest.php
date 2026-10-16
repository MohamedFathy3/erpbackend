<?php

namespace Tests\Feature;

use App\Http\Controllers\AdvancedAccessController;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MyAccessSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('permissions');
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('module')->nullable();
        });
        DB::table('permissions')->insert([
            ['key' => 'sales.invoice.create', 'name' => 'Create invoice', 'name_ar' => 'إنشاء فاتورة', 'module' => 'sales'],
            ['key' => 'inventory.view', 'name' => 'View inventory', 'name_ar' => 'عرض المخزون', 'module' => 'inventory'],
        ]);
    }

    public function test_employee_receives_only_own_branch_and_effective_permission_details(): void
    {
        $employee = new class extends Employee {
            public function permissionKeys(): array
            {
                return ['sales.invoice.create', 'inventory.view'];
            }
        };
        $employee->setRelation('role', new Role(['name' => 'Cashier']));
        $branch = new Branch(['name' => 'Main Branch', 'name_ar' => 'الفرع الرئيسي']);
        $branch->setAttribute('id', 12);
        $employee->setRelation('branch', $branch);

        $request = Request::create('/api/me/permissions', 'GET');
        $request->setUserResolver(static fn () => $employee);
        $response = app(AdvancedAccessController::class)->mePermissions($request);
        $data = $response->getData(true)['data'];

        $this->assertSame('Cashier', $data['role']);
        $this->assertSame(['id' => 12, 'name' => 'Main Branch', 'name_ar' => 'الفرع الرئيسي'], $data['branch']);
        $this->assertSame(['sales.invoice.create', 'inventory.view'], $data['permissions']);
        $this->assertCount(2, $data['permission_details']);
        $this->assertSame('إنشاء فاتورة', $data['permission_details'][1]['name_ar']);
    }

    public function test_branches_view_permission_is_seeded_for_assignment(): void
    {
        $migration = require base_path('database/migrations/2026_10_17_000001_add_branches_view_permission.php');
        $migration->up();

        $permission = DB::table('permissions')->where('key', 'branches.view')->first();
        $this->assertNotNull($permission);
        $this->assertSame('عرض فروع المنشأة', $permission->name_ar);
        $this->assertSame('branches', $permission->module);
    }
}
