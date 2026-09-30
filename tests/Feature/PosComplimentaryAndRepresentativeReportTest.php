<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\CustomerController;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesRepresentative;
use App\Models\Tenant;
use App\Notifications\SystemEventNotification;
use App\Services\PosInvoicePricingService;
use App\Services\SalesRepresentativeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosComplimentaryAndRepresentativeReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_submit_pos_discount_even_if_client_sends_one(): void
    {
        $tenant = Tenant::create(['name' => 'POS authorization tenant', 'slug' => 'pos-auth-test']);
        $product = Product::create(['name' => 'Widget', 'sku' => 'POS-AUTH-1', 'price' => 100, 'stock' => 10, 'tenant_id' => $tenant->id]);
        $cashier = (object) ['role' => null, 'super_admin' => false];

        $request = Request::create('/api/invoice/store', 'POST', [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100,
                'discount_percentage' => 5,
                'discount_amount' => 5,
            ]],
            'discount_percentage' => 0,
            'payments' => [],
        ]);
        $request->setUserResolver(fn () => $cashier);

        $response = app(InvoiceController::class)->store($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('pos_discount_permission_required', $response->getData(true)['code']);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_non_admin_without_discount_permission_cannot_create_complimentary_invoice(): void
    {
        $tenant = Tenant::create(['name' => 'Complimentary permission tenant', 'slug' => 'complimentary-permission-test']);
        $product = Product::create(['name' => 'Gift item', 'sku' => 'POS-GIFT-PERM-1', 'price' => 100, 'stock' => 5, 'tenant_id' => $tenant->id]);
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'VIP customer', 'tenant_id' => $tenant->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $request = Request::create('/api/invoice/store', 'POST', [
            'customer_id' => $customerId,
            'is_complimentary' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100]],
            'payments' => [],
        ]);
        $request->setUserResolver(fn () => (object) ['role' => null, 'super_admin' => false]);

        $response = app(InvoiceController::class)->store($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('pos_discount_permission_required', $response->getData(true)['code']);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_employee_with_explicit_pos_discount_permission_can_apply_discounts(): void
    {
        $tenant = Tenant::create(['name' => 'POS discount grant tenant', 'slug' => 'pos-discount-grant-test']);
        $product = Product::create(['name' => 'Granted widget', 'sku' => 'POS-GRANT-1', 'price' => 100, 'stock' => 10, 'tenant_id' => $tenant->id]);
        $role = Role::create(['name' => 'discount-grant-cashier', 'tenant_id' => $tenant->id]);
        $cashier = Employee::create([
            'name' => 'Authorized cashier',
            'role_id' => $role->id,
            'tenant_id' => $tenant->id,
        ]);
        $permission = Permission::query()->where(Permission::identifierColumn(), 'sales.pos_discount.apply')->firstOrFail();
        $cashier->permissions()->attach($permission->id);

        $request = Request::create('/api/invoice/store', 'POST', [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100,
                'discount_percentage' => 5,
                'discount_amount' => 5,
            ]],
            'discount_percentage' => 0,
            'payments' => [],
        ]);
        $request->setUserResolver(fn () => $cashier);
        Auth::setUser($cashier);

        try {
            $response = app(InvoiceController::class)->store($request);
        } finally {
            Auth::forgetGuards();
        }

        // The controller proceeds past the discount authorization gate and then
        // correctly refuses to post the sale because no cashier shift is open.
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('لا توجد وردية مفتوحة', $response->getData(true)['message']);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_pos_customer_list_is_scoped_to_employee_branch_even_if_client_requests_another_branch(): void
    {
        $tenant = Tenant::create(['name' => 'Branch customer tenant', 'slug' => 'pos-customer-branch-test']);
        $role = Role::create(['name' => 'branch-cashier', 'tenant_id' => $tenant->id]);
        $branchA = DB::table('branches')->insertGetId([
            'name' => 'Cashier branch', 'tenant_id' => $tenant->id, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'name' => 'Other branch', 'tenant_id' => $tenant->id, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cashier = Employee::create([
            'name' => 'Branch cashier', 'role_id' => $role->id,
            'tenant_id' => $tenant->id, 'branch_id' => $branchA,
        ]);
        $customerA = DB::table('customers')->insertGetId([
            'name' => 'Local customer', 'branch_id' => $branchA, 'tenant_id' => $tenant->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('customers')->insert([
            'name' => 'Foreign branch customer', 'branch_id' => $branchB, 'tenant_id' => $tenant->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $request = Request::create('/api/customer/index', 'POST', [
            'pos_context' => true,
            'branch_id' => $branchB,
            'filters' => ['branch_id' => $branchB],
        ]);
        $request->setUserResolver(fn () => $cashier);
        Auth::setUser($cashier);

        try {
            $response = app(CustomerController::class)->index($request);
        } finally {
            Auth::forgetGuards();
        }

        $this->assertSame([$customerA], collect($response->response()->getData(true)['data'])->pluck('id')->all());
    }

    public function test_complimentary_pos_invoice_notifies_tenant_admin_with_warning(): void
    {
        Notification::fake();
        $tenant = Tenant::create(['name' => 'Complimentary tenant', 'slug' => 'pos-complimentary-test']);
        $admin = Admin::factory()->create(['tenant_id' => $tenant->id]);

        $invoice = new Invoice([
            'invoice_number' => 'POS-GIFT-001',
            'is_complimentary' => true,
            'tenant_id' => $tenant->id,
        ]);
        $invoice->id = 9001;

        app(\App\Services\SystemNotificationService::class)->invoiceCreated($invoice, 'sale');

        Notification::assertSentTo($admin, SystemEventNotification::class, function (SystemEventNotification $notification): bool {
            return $notification->category === 'warning'
                && $notification->eventKey === 'invoice-created:invoice:9001'
                && str_contains($notification->message, 'فاتورة مجاملات');
        });
    }

    public function test_complimentary_pricing_forces_full_discount_and_zero_balance(): void
    {
        $pricing = app(PosInvoicePricingService::class)->calculate([
            ['price' => 120, 'quantity' => 2, 'discount_percentage' => 10],
            ['price' => 50, 'quantity' => 1, 'discount_percentage' => 0],
        ], 7, true);

        $this->assertSame(100.0, $pricing['invoice_discount_percentage']);
        $this->assertSame(290.0, $pricing['gross_total']);
        $this->assertSame(24.0, $pricing['item_discount_total']);
        $this->assertSame(266.0, $pricing['invoice_discount_amount']);
        $this->assertSame(290.0, $pricing['total_discount_amount']);
        $this->assertSame(100.0, $pricing['effective_discount_percentage']);
        $this->assertSame(0.0, $pricing['net_total']);
    }

    public function test_complimentary_invoice_requires_a_registered_customer(): void
    {
        $tenant = Tenant::create(['name' => 'Complimentary customer tenant', 'slug' => 'complimentary-customer-test']);
        $product = Product::create(['name' => 'Gift item', 'sku' => 'POS-GIFT-CUSTOMER-1', 'price' => 100, 'stock' => 5, 'tenant_id' => $tenant->id]);
        $request = Request::create('/api/invoice/store', 'POST', [
            'is_complimentary' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100]],
            'payments' => [],
        ]);
        $request->setUserResolver(fn () => (object) ['super_admin' => true]);

        try {
            app(InvoiceController::class)->store($request);
            $this->fail('A complimentary POS invoice must require a registered customer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_representative_report_includes_pos_items_and_historical_commission_snapshot(): void
    {
        $tenant = Tenant::create(['name' => 'Representative report tenant', 'slug' => 'rep-report-test']);
        $representative = SalesRepresentative::create([
            'name' => 'Mohamed',
            'commission_rate' => 9,
            'active' => true,
            'tenant_id' => $tenant->id,
        ]);
        $product = Product::create([
            'name' => 'Premium filter',
            'sku' => 'REP-REPORT-1',
            'price' => 100,
            'stock' => 5,
            'tenant_id' => $tenant->id,
        ]);
        $invoice = Invoice::create([
            'invoice_number' => 'POS-REP-001',
            'total_amount' => 200,
            'paid_amount' => 200,
            'remaining_amount' => 0,
            'sales_representative_id' => $representative->id,
            'commission_rate_snapshot' => 5,
            'commission_amount_snapshot' => 10,
            'status' => 'paid',
            'created_at' => '2026-09-25 10:00:00',
            'updated_at' => '2026-09-25 10:00:00',
            'tenant_id' => $tenant->id,
        ]);
        $invoice->items()->create([
            'product_id' => $product->id,
            'product_name' => 'Premium filter',
            'quantity' => 2,
            'price' => 100,
            'total' => 200,
            'tenant_id' => $tenant->id,
        ]);

        $report = app(SalesRepresentativeReportService::class)->report($representative, '2026-09-25', '2026-09-25');

        $this->assertSame(1, $report['summary']['invoice_count']);
        $this->assertSame(200.0, $report['summary']['sales_total']);
        $this->assertSame(10.0, $report['summary']['commission_total']);
        $this->assertSame(5.0, $report['invoices'][0]['commission_rate']);
        $this->assertSame('Premium filter', $report['invoices'][0]['items'][0]['product_name']);
        $this->assertSame(2.0, $report['invoices'][0]['items'][0]['quantity']);
        $this->assertSame('2026-09-25', $report['daily'][0]['period']);
    }
}
