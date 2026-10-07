<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ReturnInvoice;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\WorkflowTransaction;
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

    public function test_pos_return_reverses_revenue_cost_and_commission_once(): void
    {
        $treasury = Treasury::create(['name' => 'POS return cash', 'balance' => 0]);
        app(AccountLedgerService::class)->initializeTreasuryAccount($treasury);
        $product = Product::create(['name' => 'Returned POS item', 'price' => 10, 'cost' => 5, 'stock' => 9]);
        $invoice = Invoice::create([
            'invoice_number' => 'POS-RETURN-TEST-1',
            'total_amount' => 20,
            'paid_amount' => 20,
            'remaining_amount' => 0,
            'status' => 'paid',
            'treasury_id' => $treasury->id,
            'commission_rate_snapshot' => 10,
            'commission_amount_snapshot' => 2,
        ]);
        $invoice->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'price' => 10,
            'total' => 20,
        ]);
        $posting = app(PosAccountingPostingService::class);
        $commissionJournal = $posting->postCommission($invoice);

        $return = ReturnInvoice::create([
            'invoice_id' => $invoice->id,
            'total_amount' => 10,
            'refunded_amount' => 5,
            'refund_method' => 'cash',
        ]);
        $return->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price' => 10,
            'total' => 10,
        ]);

        $returnJournal = $posting->postReturn($return);
        $journals = JournalEntry::query()->whereIn('id', [
            $commissionJournal->id,
            $returnJournal->id,
            WorkflowTransaction::query()->where('event_key', 'pos-return-cogs:' . $return->id)->value('journal_entry_id'),
            WorkflowTransaction::query()->where('event_key', 'pos-return-commission:' . $return->id)->value('journal_entry_id'),
        ])->with('lines')->get();

        $this->assertCount(4, $journals);
        foreach ($journals as $journal) {
            $this->assertEquals($journal->lines->sum('debit'), $journal->lines->sum('credit'));
        }

        $this->assertEquals(10, Account::where('code', '4090-SALES-RETURNS')->value('debit'));
        $this->assertEquals(1, Account::where('code', 'SALES-COMMISSIONS-PAYABLE')->value('debit'));
        $this->assertSame($returnJournal->id, $posting->postReturn($return->fresh())->id);
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_existing_paid_invoice_can_be_refunded_and_converted_to_complimentary(): void
    {
        $treasury = Treasury::create(['name' => 'Complimentary conversion cash', 'balance' => 0]);
        app(AccountLedgerService::class)->initializeTreasuryAccount($treasury);
        $product = Product::create(['name' => 'Complimentary conversion item', 'price' => 20, 'cost' => 5, 'stock' => 9]);
        $invoice = Invoice::create([
            'invoice_number' => 'POS-COMP-TEST-1',
            'total_amount' => 20,
            'paid_amount' => 20,
            'remaining_amount' => 0,
            'status' => 'paid',
            'treasury_id' => $treasury->id,
            'commission_rate_snapshot' => 10,
            'commission_amount_snapshot' => 2,
        ]);
        $invoice->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price' => 20,
            'total' => 20,
        ]);
        $payment = $invoice->payments()->create(['method' => 'cash', 'amount' => 20]);
        $posting = app(PosAccountingPostingService::class);
        $saleJournal = $posting->postSale($invoice);
        $cogsJournal = $posting->postCogs($invoice->fresh());
        $commissionJournal = $posting->postCommission($invoice->fresh());
        $paymentJournal = $posting->postPayment($invoice->fresh(), $payment);
        $treasury->increment('balance', 20);

        $converted = $posting->makeComplimentary($invoice->fresh());
        $converted->refresh();

        $this->assertTrue((bool) $converted->is_complimentary);
        $this->assertEquals(20, $converted->total_amount);
        $this->assertEquals(0, $converted->net_amount);
        $this->assertEquals(0, $converted->net_paid_amount);
        $this->assertEquals(0, $converted->net_commission_amount);
        $this->assertEquals(0, $treasury->fresh()->balance);
        $this->assertSame('posted', $cogsJournal->fresh()->status);
        $this->assertSame('cancelled', $saleJournal->fresh()->status);
        $this->assertSame('cancelled', $commissionJournal->fresh()->status);
        $this->assertSame('cancelled', $paymentJournal->fresh()->status);
        $this->assertDatabaseHas('treasury_transactions', [
            'treasury_id' => $treasury->id,
            'reference_type' => Invoice::class,
            'reference_id' => $invoice->id,
            'type' => 'out',
            'amount' => 20,
        ]);

        $journalCount = JournalEntry::count();
        $posting->makeComplimentary($invoice->fresh());
        $this->assertDatabaseCount('journal_entries', $journalCount);
        $this->assertEquals(0, $treasury->fresh()->balance);
    }
}
