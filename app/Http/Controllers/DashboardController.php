<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
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
            'month_purchases' => (float) $monthPurchases->sum('total_amount'),
            'net_profit_before_overheads' => (float) $monthSales->sum('net_total') - (float) $monthPurchases->sum('total_amount'),
            'sales_invoices_count' => (int) $sales->count(),
            'purchase_invoices_count' => (int) $purchases->count(),
            'customers_count' => Customer::count(),
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
