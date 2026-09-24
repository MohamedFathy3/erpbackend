<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Finance;
use App\Models\JournalEntry;
use App\Models\Transfer;
use App\Models\Treasury;
use App\Services\AccountingAutoPostingService;
use App\Services\TransferPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_treasury_transfer_posts_balanced_entry_once_and_links_each_cash_account(): void
    {
        $source = Treasury::create(['name' => 'Source', 'balance' => 100]);
        $destination = Treasury::create(['name' => 'Destination', 'balance' => 0]);
        $transfer = Transfer::create([
            'type' => 'treasury_to_treasury',
            'from_treasury_id' => $source->id,
            'to_treasury_id' => $destination->id,
            'amount' => 25,
            'currency' => 'EGP',
        ]);
        $posting = app(TransferPostingService::class);

        $journal = $posting->post($transfer);
        $again = $posting->post($transfer->fresh());

        $this->assertSame($journal->id, $again->id);
        $this->assertSame($journal->id, $transfer->fresh()->journal_entry_id);
        $this->assertCount(2, $journal->lines);
        $this->assertEquals(25, $journal->lines->sum('debit'));
        $this->assertEquals(25, $journal->lines->sum('credit'));
        $this->assertNotNull($source->fresh()->account_id);
        $this->assertNotNull($destination->fresh()->account_id);
        $this->assertDatabaseCount('journal_entries', 1);

        $debitAccount = Account::findOrFail($journal->lines->firstWhere('debit', '>', 0)->account_id);
        $creditAccount = Account::findOrFail($journal->lines->firstWhere('credit', '>', 0)->account_id);
        $this->assertEquals(25, $debitAccount->debit);
        $this->assertEquals(25, $creditAccount->credit);
    }

    public function test_expense_posts_balanced_entry_and_is_idempotent_without_a_treasury(): void
    {
        $expense = Finance::create([
            'category' => 'utilities',
            'amount' => 12.50,
            'description' => 'Electricity',
            'date' => '2026-09-24',
            'payment_method' => 'other',
        ]);
        $posting = app(AccountingAutoPostingService::class);

        $journal = $posting->postFinance($expense);
        $again = $posting->postFinance($expense->fresh());

        $this->assertSame($journal->id, $again->id);
        $this->assertSame($journal->id, $expense->fresh()->journal_entry_id);
        $this->assertCount(2, $journal->lines);
        $this->assertEquals(12.50, $journal->lines->sum('debit'));
        $this->assertEquals(12.50, $journal->lines->sum('credit'));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseHas('accounts', ['code' => 'UNALLOCATED-CASH']);
    }
}
