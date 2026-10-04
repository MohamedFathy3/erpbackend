<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Invoice;
use App\Models\PurchaseReturn;
use App\Models\Finance;
use App\Models\Revenue;
use App\Models\JournalEntry;
use App\Models\Employee;
use App\Models\WorkflowTransaction;
use App\Models\Project;
use App\Models\ProjectClaim;
use App\Models\ProjectCostEntry;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingCostEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function unifiedFinancialReport(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $branchId = $request->integer('branch_id') ?: null;
        $user = auth()->user();
        $isAdmin = (bool) ($user?->super_admin ?? false) || strtolower((string) ($user?->role ?? '')) === 'admin';

        $scoped = function ($query, ?string $dateColumn = null) use ($isAdmin, $branchId, $from, $to) {
            if ($isAdmin) $query->withoutGlobalScope('branch');
            if ($branchId) $query->where('branch_id', $branchId);
            if ($dateColumn) $query->whereDate($dateColumn, '>=', $from)->whereDate($dateColumn, '<=', $to);
            return $query;
        };

        $sales = $scoped(SalesInvoice::with('items.product'), 'invoice_date')->get();
        $posSales = $scoped(Invoice::with('items.product'), 'created_at')->get();
        $purchases = $scoped(PurchaseInvoice::with('items.product'), 'invoice_date')->get();
        $purchaseReturns = $scoped(PurchaseReturn::query(), 'return_date')->get();
        $expenses = $scoped(Finance::query()->whereNotIn('category', ['revenue', 'income']), 'date')->get();
        $revenues = $scoped(Revenue::query(), 'date')->get();
        $journals = $scoped(JournalEntry::with('lines'), 'entry_date')->get();
        $employeesQuery = Employee::query();
        if ($isAdmin) $employeesQuery->withoutGlobalScope('branch');
        if ($branchId) $employeesQuery->where('branch_id', $branchId);
        $employees = $employeesQuery->where('active', true)->orderBy('name')->get(['id', 'name', 'branch_id']);

        $salesTotal = (float) $sales->sum(fn ($invoice) => $invoice->net_total ?? $invoice->total_amount ?? 0) + (float) $posSales->sum('total_amount');
        $salesCost = (float) $sales->sum(fn ($invoice) => $invoice->items->sum(fn ($item) => (float) $item->quantity * (float) ($item->product?->cost ?? 0))) + (float) $posSales->sum(fn ($invoice) => $invoice->items->sum(fn ($item) => (float) $item->quantity * (float) ($item->product?->cost ?? 0)));
        $purchaseTotal = (float) $purchases->sum('total_amount');
        $purchaseReturnTotal = (float) $purchaseReturns->sum('total_amount');
        $expenseTotal = (float) $expenses->sum('amount');
        $revenueTotal = (float) $revenues->sum('amount');
        $grossProfit = $salesTotal - $salesCost;
        $netProfit = $grossProfit + $revenueTotal - $expenseTotal;

        return response()->json(['status' => true, 'data' => [
            'filters' => ['from' => $from, 'to' => $to, 'branch_id' => $branchId],
            'summary' => [
                'sales' => round($salesTotal, 2), 'sales_cost' => round($salesCost, 2),
                'gross_profit' => round($grossProfit, 2), 'purchases' => round($purchaseTotal, 2),
                'purchase_returns' => round($purchaseReturnTotal, 2), 'expenses' => round($expenseTotal, 2),
                'revenues' => round($revenueTotal, 2), 'net_profit' => round($netProfit, 2),
                'sales_count' => $sales->count() + $posSales->count(), 'purchase_count' => $purchases->count(),
                'expense_count' => $expenses->count(), 'revenue_count' => $revenues->count(),
                'journal_count' => $journals->count(), 'employee_count' => $employees->count(),
            ],
            'breakdown' => [
                'sales' => ['regular' => round((float) $sales->sum(fn ($i) => $i->net_total ?? $i->total_amount ?? 0), 2), 'pos' => round((float) $posSales->sum('total_amount'), 2)],
                'journal' => ['debit' => round((float) $journals->sum(fn ($j) => $j->lines->sum('debit')), 2), 'credit' => round((float) $journals->sum(fn ($j) => $j->lines->sum('credit')), 2)],
            ],
            'employees' => $employees->map(fn ($employee) => ['id' => $employee->id, 'name' => $employee->name, 'branch_id' => $employee->branch_id])->values(),
        ]]);
    }

    public function summary(Request $request)
    {
        $branchId = $request->integer('branch_id') ?: null;
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $sales = SalesInvoice::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $purchases = PurchaseInvoice::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $monthSales = (clone $sales)->whereDate('invoice_date', '>=', $monthStart);
        $monthPurchases = (clone $purchases)->whereDate('invoice_date', '>=', $monthStart);
        $todaySales = (clone $sales)->whereDate('invoice_date', $today);
        $lowStock = Product::whereColumn('stock', '<=', DB::raw('CASE WHEN reorder_level > 0 THEN reorder_level ELSE 5 END'))->count();
        $projects = Project::query();
        $claims = ProjectClaim::query();
        $manufacturing = ManufacturingOrder::query();

        return response()->json(['status' => true, 'data' => [
            'today_sales' => (float) $todaySales->sum('net_total'),
            'month_sales' => (float) $monthSales->sum('net_total'),
            'total_sales' => (float) $sales->sum('net_total'),
            'total_sales_amount' => (float) $sales->sum('net_total'),
            'month_purchases' => (float) $monthPurchases->sum('total_amount'),
            'net_profit_before_overheads' => (float) $monthSales->sum('net_total') - (float) $monthPurchases->sum('total_amount'),
            'sales_invoices_count' => (int) $sales->count(),
            'purchase_invoices_count' => (int) $purchases->count(),
            'customers_count' => Customer::count(),
            'loyalty_points' => (int) Customer::sum('point'),
            'products_count' => Product::count(),
            'low_stock_count' => $lowStock,
            'credit_sales' => (float) (clone $monthSales)->whereIn('payment_method', ['credit', 'on_account', 'آجل'])->sum('net_total'),
            'recent_workflows' => WorkflowTransaction::latest('occurred_at')->limit(8)->get(['id', 'event', 'status', 'source_type', 'source_id', 'occurred_at']),
            'projects_finance' => [
                'active_count' => (int) (clone $projects)->where('status', 'active')->count(),
                'contract_value' => (float) (clone $projects)->whereIn('status', ['active', 'completed'])->sum('contract_value'),
                'budget_cost' => (float) (clone $projects)->sum('budget_cost'),
                'actual_cost' => (float) (clone $projects)->sum('actual_cost'),
                'claims_gross' => (float) (clone $claims)->whereIn('status', ['submitted', 'approved', 'partially_paid', 'paid'])->sum('gross_amount'),
                'claims_net' => (float) (clone $claims)->whereIn('status', ['submitted', 'approved', 'partially_paid', 'paid'])->sum('net_amount'),
                'claims_paid' => (float) (clone $claims)->sum('paid_amount'),
                'claims_outstanding' => (float) (clone $claims)->whereIn('status', ['approved', 'partially_paid'])->sum(DB::raw('net_amount - paid_amount')),
                'pending_claims' => (int) (clone $claims)->whereIn('status', ['submitted', 'approved', 'partially_paid'])->count(),
                'profit_estimate' => (float) (clone $projects)->whereIn('status', ['active', 'completed'])->sum(DB::raw('contract_value - actual_cost')),
            ],
            'manufacturing_summary' => [
                'orders_in_progress' => (int) (clone $manufacturing)->whereIn('status', ['planned', 'in_progress', 'quality_hold'])->count(),
                'completed_orders' => (int) (clone $manufacturing)->where('status', 'completed')->count(),
                'planned_quantity' => (float) (clone $manufacturing)->sum('planned_quantity'),
                'produced_quantity' => (float) (clone $manufacturing)->sum('produced_quantity'),
                'actual_cost' => (float) ManufacturingCostEntry::sum('amount'),
                'planned_cost' => (float) (clone $manufacturing)->sum('planned_cost'),
            ],
        ]]);
    }
}
