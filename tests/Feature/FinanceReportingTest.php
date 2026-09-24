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

    public function test_posted_rent_expense_appears_in_income_statement_and_leaf_trial_balance(): void
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
        $this->assertFalse(collect($trial['accounts'])->contains('id', $parentId));

        $incomeResponse = app(AccountingCoreController::class)->incomeStatement($request);
        $income = $incomeResponse->getData(true)['data'];
        $this->assertEquals(33333, $income['expenses']);
        $this->assertEquals(-33333, $income['net_profit']);
    }
}
