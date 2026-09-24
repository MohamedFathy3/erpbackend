<?php

namespace Tests\Feature;

use App\Http\Controllers\AccountingCoreController;
use App\Http\Controllers\FinanceController;
use App\Models\Account;
use App\Models\Finance;
use App\Services\AccountingAutoPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class FinanceReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_index_filters_expenses_by_date_and_category(): void
    {
        Finance::create(['category' => 'rent', 'amount' => 33333, 'description' => 'September rent', 'date' => '2026-09-24', 'payment_method' => 'cash']);
        Finance::create(['category' => 'rent', 'amount' => 1000, 'description' => 'August rent', 'date' => '2026-08-24', 'payment_method' => 'cash']);
        Finance::create(['category' => 'utilities', 'amount' => 50, 'description' => 'September utilities', 'date' => '2026-09-24', 'payment_method' => 'cash']);

        $request = Request::create('/api/finance/index', 'POST', [
            'filters' => ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'category' => 'rent'],
            'paginate' => false,
        ]);
        $response = app(FinanceController::class)->index($request)->toResponse($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $payload['data']);
        $this->assertSame('September rent', $payload['data'][0]['description']);
        $this->assertSame(33333.0, (float) $payload['data'][0]['amount']);
    }

    public function test_posted_rent_expense_rolls_up_to_parent_without_double_counting_totals(): void
    {
        $expense = Finance::create([
            'category' => 'rent',
            'amount' => 33333,
            'description' => 'Office rent',
            'date' => '2026-09-24',
            'payment_method' => 'other',
        ]);
        app(AccountingAutoPostingService::class)->postFinance($expense);

        $request = Request::create('/api/accounting/reports/trial-balance', 'GET');
        $trialResponse = app(AccountingCoreController::class)->trialBalance($request);
        $trial = $trialResponse->getData(true)['data'];
        $rentAccount = Account::query()->where('code', 'like', 'EXP-CATEGORY-%')->firstOrFail();
        $parentId = $rentAccount->parent_id;
        $trialRow = collect($trial['accounts'])->firstWhere('id', $rentAccount->id);

        $this->assertNotNull($trialRow);
        $this->assertEquals(33333, $trialRow['period_debit']);
        $parentRow = collect($trial['accounts'])->firstWhere('id', $parentId);
        $this->assertNotNull($parentRow);
        $this->assertEquals(33333, $parentRow['period_debit']);
        // Roll-up rows are shown, but grand totals are calculated from posted lines once.
        $this->assertEquals(33333, $trial['totals']['debit']);

        $incomeResponse = app(AccountingCoreController::class)->incomeStatement($request);
        $income = $incomeResponse->getData(true)['data'];
        $this->assertEquals(33333, $income['expenses']);
        $this->assertEquals(-33333, $income['net_profit']);
        $incomeParent = collect($income['lines'])->firstWhere('id', $parentId);
        $this->assertNotNull($incomeParent);
        $this->assertEquals(33333, $incomeParent['period_debit']);
    }

    public function test_general_ledger_includes_opening_balance_before_selected_period(): void
    {
        $prior = Finance::create(['category' => 'rent', 'amount' => 100, 'description' => 'Prior rent', 'date' => '2026-08-20', 'payment_method' => 'other']);
        $current = Finance::create(['category' => 'rent', 'amount' => 50, 'description' => 'Current rent', 'date' => '2026-09-10', 'payment_method' => 'other']);
        app(AccountingAutoPostingService::class)->postFinance($prior);
        app(AccountingAutoPostingService::class)->postFinance($current);
        $account = Account::query()->where('code', 'like', 'EXP-CATEGORY-%')->firstOrFail();
        $request = Request::create('/api/accounting/accounts/' . $account->id . '/ledger', 'GET', [
            'from' => '2026-09-01', 'to' => '2026-09-30',
        ]);

        $payload = app(AccountingCoreController::class)->ledger($request, $account)->getData(true)['data'];

        $this->assertEquals(100, $payload['opening_balance']);
        $this->assertCount(1, $payload['rows']);
        $this->assertEquals(150, $payload['balance']);
        $this->assertEquals(150, $payload['rows'][0]['balance']);
    }
}
