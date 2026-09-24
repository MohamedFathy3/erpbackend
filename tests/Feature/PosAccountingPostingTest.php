<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Treasury;
use App\Services\AccountLedgerService;
use App\Services\PosAccountingPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosAccountingPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_sale_payment_and_cost_are_posted_once_and_balanced(): void
    {
        $treasury = Treasury::create(['name' => 'POS cash', 'balance' => 0]);
        app(AccountLedgerService::class)->initializeTreasuryAccount($treasury);
        $product = Product::create(['name' => 'POS item', 'price' => 20, 'cost' => 5, 'stock' => 10]);
        $invoice = Invoice::create([
            'invoice_number' => 'POS-TEST-1',
            'total_amount' => 20,
            'paid_amount' => 12,
            'remaining_amount' => 8,
            'status' => 'partial',
            'treasury_id' => $treasury->id,
        ]);
        $invoice->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price' => 20,
            'total' => 20,
        ]);
        $payment = $invoice->payments()->create(['method' => 'cash', 'amount' => 12]);
        $posting = app(PosAccountingPostingService::class);

        $saleJournal = $posting->postSale($invoice);
        $paymentJournal = $posting->postPayment($invoice, $payment);
        $cogsJournal = $posting->postCogs($invoice);
        $this->assertSame($saleJournal->id, $posting->postSale($invoice->fresh())->id);
        $this->assertSame($paymentJournal->id, $posting->postPayment($invoice, $payment->fresh())->id);
        $this->assertSame($cogsJournal->id, $posting->postCogs($invoice->fresh())->id);

        $invoice->refresh();
        $payment->refresh();
        $this->assertSame($saleJournal->id, $invoice->journal_entry_id);
        $this->assertSame($paymentJournal->id, $payment->journal_entry_id);
        $this->assertSame($cogsJournal->id, $invoice->cogs_journal_entry_id);
        foreach ([$saleJournal, $paymentJournal, $cogsJournal] as $journal) {
            $this->assertEquals($journal->lines->sum('debit'), $journal->lines->sum('credit'));
        }
        $cashAccount = Account::findOrFail($treasury->fresh()->account_id);
        $this->assertEquals(12, $cashAccount->debit);
        $this->assertDatabaseCount('journal_entries', 3);
    }
}
