<?php

namespace App\Http\Controllers;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceReturn;
use App\Models\SalesRepresentative;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SalesRepresentativeAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $representative = SalesRepresentative::query()
            ->where('email', $data['identifier'])
            ->orWhere('phone', $data['identifier'])
            ->first();

        if (!$representative || !$representative->active || !$representative->password || !Hash::check($data['password'], $representative->password)) {
            return response()->json(['message' => 'بيانات الدخول غير صحيحة أو الحساب غير فعال'], 401);
        }

        $representative->forceFill(['last_login_at' => now()])->save();
        $token = $representative->createToken('sales-representative-token', ['representative'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'type' => 'sales_representative',
            'data' => $representative->load('branch'),
        ]);
    }

    public function me(Request $request)
    {
        $representative = $this->representative($request);
        return response()->json(['data' => $representative->load('branch')]);
    }

    public function logout(Request $request)
    {
        $representative = $this->representative($request);
        $representative->currentAccessToken()?->delete();
        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }

    public function dashboard(Request $request)
    {
        $representative = $this->representative($request);
        $from = $request->date('from');
        $to = $request->date('to');

        $invoices = SalesInvoice::query()
            ->with(['customer:id,name', 'salesRepresentative:id,name,commission_rate'])
            ->where('sales_representative_id', $representative->id)
            ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
            ->latest('invoice_date')
            ->get();

        $returns = SalesInvoiceReturn::query()
            ->whereHas('invoice', fn ($query) => $query->where('sales_representative_id', $representative->id))
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->get();

        $salesTotal = (float) $invoices->sum(fn ($invoice) => $invoice->net_total ?? $invoice->total_amount ?? 0);
        $paidTotal = (float) $invoices->sum('paid_amount');
        $returnsTotal = (float) $returns->sum('total_amount');
        $netSales = max(0, $salesTotal - $returnsTotal);
        $commissionRate = (float) ($representative->commission_rate ?? 0);

        $periods = $invoices->groupBy(fn ($invoice) => optional($invoice->invoice_date ?? $invoice->created_at)->format('Y-m'))
            ->map(fn ($rows, $period) => [
                'period' => $period,
                'invoice_count' => $rows->count(),
                'sales_total' => (float) $rows->sum(fn ($invoice) => $invoice->net_total ?? $invoice->total_amount ?? 0),
                'commission' => (float) $rows->sum(fn ($invoice) => (($invoice->net_total ?? $invoice->total_amount ?? 0) * $commissionRate) / 100),
            ])->values();

        return response()->json([
            'data' => [
                'representative' => $representative->only(['id', 'name', 'email', 'phone', 'commission_rate', 'active', 'branch_id']),
                'summary' => [
                    'invoice_count' => $invoices->count(),
                    'sales_total' => round($salesTotal, 2),
                    'paid_total' => round($paidTotal, 2),
                    'returns_total' => round($returnsTotal, 2),
                    'net_sales' => round($netSales, 2),
                    'commission_rate' => $commissionRate,
                    'commission_total' => round(($netSales * $commissionRate) / 100, 2),
                ],
                'periods' => $periods,
                'invoices' => $invoices->take(200)->values(),
                'returns' => $returns->values(),
            ],
        ]);
    }

    private function representative(Request $request): SalesRepresentative
    {
        $user = $request->user();
        abort_unless($user instanceof SalesRepresentative, 403, 'حساب المندوب غير صالح لهذا المسار');
        return $user;
    }
}
