<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchaseInvoiceController;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Treasury;
use App\Services\WorkflowPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SupplierPaymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_payment_date_and_details_are_saved_and_returned(): void
    {
        $supplier = Supplier::create(['name' => 'Payment history supplier']);
        $treasury = Treasury::create(['name' => 'Payment treasury', 'balance' => 1000]);
        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-PAYMENT-TEST',
            'supplier_id' => $supplier->id,
            'total_amount' => 100,
            'paid_amount' => 0,
            'invoice_date' => '2026-09-18',
            'payment_method' => 'credit',
        ]);
        $request = Request::create('/api/purchase-invoices/' . $invoice->id . '/pay', 'PATCH', [
            'amount' => 25,
            'treasury_id' => $treasury->id,
            'payment_date' => '2026-09-20',
            'payment_method' => 'cash',
            'reference_number' => 'RCPT-25',
            'notes' => 'دفعة اختبار',
        ]);

        $response = app(PurchaseInvoiceController::class)->pay($request, $invoice, app(WorkflowPostingService::class));
        $payload = $response->getData(true);
        $this->assertSame(200, $response->getStatusCode(), json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->assertSame(75.0, (float) $payload['remaining']);
        $this->assertSame('2026-09-20', $payload['payment']['payment_date']);
        $this->assertSame('RCPT-25', $payload['payment']['reference_number']);
        $this->assertSame('2026-09-20', $payload['invoice']['payments'][0]['payment_date']);
        $this->assertSame('دفعة اختبار', $payload['invoice']['payments'][0]['notes']);
        $this->assertNotNull($payload['invoice']['payments'][0]['journal_entry_id']);
    }
}
