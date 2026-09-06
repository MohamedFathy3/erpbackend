<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\WorkflowTransaction;
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
        ]]);
    }
}
