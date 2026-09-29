<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Models\Admin;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesRepresentative;
use App\Models\Tenant;
use App\Notifications\SystemEventNotification;
use App\Services\SalesRepresentativeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
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
        $this->assertSame('pos_discount_admin_only', $response->getData(true)['code']);
        $this->assertDatabaseCount('invoices', 0);
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
