<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ReturnInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceReturn;
use App\Models\SalesRepresentative;
use Illuminate\Support\Collection;

class SalesRepresentativeReportService
{
    public function report(SalesRepresentative $representative, $from = null, $to = null): array
    {
        $rate = (float) ($representative->commission_rate ?? 0);

        $posInvoices = Invoice::query()
            ->with(['customer:id,name', 'items.product:id,name,cost'])
            ->where('sales_representative_id', $representative->id)
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->get()
            ->map(fn (Invoice $invoice) => $this->normalizePosInvoice($invoice, $rate));

        $salesInvoices = SalesInvoice::query()
            ->with(['customer:id,name', 'items.product:id,name,cost'])
            ->where('sales_representative_id', $representative->id)
            ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
            ->get()
            ->map(fn (SalesInvoice $invoice) => $this->normalizeSalesInvoice($invoice, $rate));

        /** @var Collection<int, array> $invoices */
        $invoices = $posInvoices->concat($salesInvoices)
            ->sortByDesc('date')
            ->values();

        $posReturns = ReturnInvoice::query()
            ->with('invoice:id,invoice_number,sales_representative_id')
            ->whereHas('invoice', fn ($query) => $query->where('sales_representative_id', $representative->id))
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->get()
            ->map(fn (ReturnInvoice $return) => [
                'id' => $return->id,
                'source_type' => 'pos',
                'invoice_number' => $return->return_number,
                'original_invoice_number' => $return->invoice?->invoice_number,
                'total_amount' => (float) ($return->total_amount ?? $return->refunded_amount ?? 0),
                'created_at' => optional($return->created_at)->toDateTimeString(),
                'status' => $return->status,
            ]);

        $salesReturns = SalesInvoiceReturn::query()
            ->with('invoice:id,invoice_number,sales_representative_id')
            ->whereHas('invoice', fn ($query) => $query->where('sales_representative_id', $representative->id))
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->get()
            ->map(fn (SalesInvoiceReturn $return) => [
                'id' => $return->id,
                'source_type' => 'sales_invoice',
                'invoice_number' => $return->return_number,
                'original_invoice_number' => $return->invoice?->invoice_number,
                'total_amount' => (float) ($return->total_amount ?? 0),
                'created_at' => optional($return->created_at)->toDateTimeString(),
                'status' => $return->workflow_status ?? $return->status,
            ]);
        $returns = $posReturns->concat($salesReturns)->filter(
            fn (array $return) => strtolower((string) ($return['status'] ?? '')) !== 'cancelled'
        )->values();

        $salesTotal = (float) $invoices->sum('total_amount');
        $extraChargeTotal = (float) $invoices->sum('extra_charge');
        $costTotal = (float) $invoices->sum('cost_total');
        $commissionTotal = (float) $invoices->sum('commission_amount');
        $paidTotal = (float) $invoices->sum('paid_amount');
        $returnsTotal = (float) $returns->sum('total_amount');

        $daily = $invoices->groupBy(fn (array $invoice) => substr((string) $invoice['date'], 0, 10))
            ->map(fn (Collection $rows, string $day) => [
                'period' => $day,
                'invoice_count' => $rows->count(),
                'sales_total' => round((float) $rows->sum('total_amount'), 2),
                'extra_charge_total' => round((float) $rows->sum('extra_charge'), 2),
                'cost_total' => round((float) $rows->sum('cost_total'), 2),
                'profit_total' => round((float) $rows->sum('profit_total'), 2),
                'commission' => round((float) $rows->sum('commission_amount'), 2),
            ])->values();

        $monthly = $invoices->groupBy(fn (array $invoice) => substr((string) $invoice['date'], 0, 7))
            ->map(fn (Collection $rows, string $month) => [
                'period' => $month,
                'invoice_count' => $rows->count(),
                'sales_total' => round((float) $rows->sum('total_amount'), 2),
                'extra_charge_total' => round((float) $rows->sum('extra_charge'), 2),
                'cost_total' => round((float) $rows->sum('cost_total'), 2),
                'profit_total' => round((float) $rows->sum('profit_total'), 2),
                'commission' => round((float) $rows->sum('commission_amount'), 2),
            ])->values();

        return [
            'representative' => [
                'id' => $representative->id,
                'name' => $representative->name,
                'phone' => $representative->phone,
                'email' => $representative->email,
                'commission_rate' => $rate,
                'branch_id' => $representative->branch_id,
            ],
            'summary' => [
                'invoice_count' => $invoices->count(),
                'sales_total' => round($salesTotal, 2),
                'cost_total' => round($costTotal, 2),
                'profit_total' => round($salesTotal - $costTotal, 2),
                'paid_total' => round($paidTotal, 2),
                'returns_total' => round($returnsTotal, 2),
                'net_sales' => round(max(0, $salesTotal - $returnsTotal), 2),
                'commission_rate' => $rate,
                'commission_total' => round($commissionTotal, 2),
                'extra_charge_total' => round($extraChargeTotal, 2),
            ],
            'periods' => $monthly,
            'daily' => $daily,
            'invoices' => $invoices->take(500)->values(),
            'returns' => $returns->values(),
        ];
    }

    private function normalizePosInvoice(Invoice $invoice, float $fallbackRate): array
    {
        $rate = $invoice->commission_rate_snapshot !== null ? (float) $invoice->commission_rate_snapshot : $fallbackRate;
        $total = $invoice->is_complimentary ? 0 : (float) ($invoice->total_amount ?? 0);
        $commission = (float) $invoice->net_commission_amount;
        $costTotal = (float) $invoice->items->sum(fn ($item) => (float) ($item->cost ?? $item->product?->cost ?? 0) * (float) ($item->quantity ?? 0));

        return [
            'id' => $invoice->id,
            'source_type' => 'pos',
            'invoice_number' => $invoice->invoice_number,
            'date' => optional($invoice->created_at)->toDateTimeString(),
            'created_at' => optional($invoice->created_at)->toDateTimeString(),
            'customer' => $invoice->customer ? ['id' => $invoice->customer->id, 'name' => $invoice->customer->name] : null,
            'total_amount' => $total,
            'net_total' => $total,
            'extra_charge' => round((float) ($invoice->extra_charge ?? 0), 2),
            'paid_amount' => (float) ($invoice->paid_amount ?? 0),
            'status' => $invoice->status,
            'commission_rate' => $rate,
            'commission_amount' => round($commission, 2),
            'cost_total' => round($costTotal, 2),
            'profit_total' => round($total - $costTotal, 2),
            'is_complimentary' => (bool) ($invoice->is_complimentary ?? false),
            'items' => $invoice->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name ?: ($item->product?->name ?? ('#' . $item->product_id)),
                'quantity' => (float) ($item->quantity ?? 0),
                'price' => (float) ($item->price ?? 0),
                'cost' => (float) ($item->cost ?? $item->product?->cost ?? 0),
                'total' => (float) ($item->total ?? 0),
            ])->values(),
        ];
    }

    private function normalizeSalesInvoice(SalesInvoice $invoice, float $fallbackRate): array
    {
        $rate = $invoice->commission_rate_snapshot !== null ? (float) $invoice->commission_rate_snapshot : $fallbackRate;
        $total = (float) ($invoice->net_total ?? $invoice->total_amount ?? 0);
        $commission = $invoice->commission_amount_snapshot !== null
            ? (float) $invoice->commission_amount_snapshot
            : round($total * $rate / 100, 2);
        $costTotal = (float) $invoice->items->sum(fn ($item) => (float) ($item->cost ?? $item->product?->cost ?? 0) * (float) ($item->quantity ?? 0));

        return [
            'id' => $invoice->id,
            'source_type' => 'sales_invoice',
            'invoice_number' => $invoice->invoice_number,
            'date' => (string) ($invoice->invoice_date ?? $invoice->created_at),
            'created_at' => optional($invoice->created_at)->toDateTimeString(),
            'customer' => $invoice->customer ? ['id' => $invoice->customer->id, 'name' => $invoice->customer->name] : null,
            'total_amount' => $total,
            'net_total' => $total,
            'paid_amount' => (float) ($invoice->paid_amount ?? 0),
            'status' => $invoice->payment_status ?? $invoice->workflow_status ?? null,
            'commission_rate' => $rate,
            'commission_amount' => round($commission, 2),
            'cost_total' => round($costTotal, 2),
            'profit_total' => round($total - $costTotal, 2),
            'is_complimentary' => false,
            'items' => $invoice->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name ?? ('#' . $item->product_id),
                'quantity' => (float) ($item->quantity ?? 0),
                'price' => (float) ($item->price ?? 0),
                'cost' => (float) ($item->cost ?? $item->product?->cost ?? 0),
                'total' => (float) ($item->total ?? 0),
            ])->values(),
        ];
    }
}
