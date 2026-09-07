<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductLedgerController extends Controller
{
    public function show(Request $request, Product $product): JsonResponse
    {
        $from = $request->date('from');
        $to = $request->date('to');

        $sales = $product->loadMissing('media')->salesInvoiceItems()
            ->with(['salesInvoice.customer', 'salesInvoice.warehouse'])
            ->when($from, fn ($q) => $q->whereHas('salesInvoice', fn ($i) => $i->whereDate('invoice_date', '>=', $from)))
            ->when($to, fn ($q) => $q->whereHas('salesInvoice', fn ($i) => $i->whereDate('invoice_date', '<=', $to)))
            ->get()->map(function ($item) {
                $invoice = $item->salesInvoice;
                $total = (float) ($item->total ?? ((float) $item->quantity * (float) $item->price));
                $invoiceTotal = (float) ($invoice->net_total ?? $invoice->total_amount ?? 0);
                $paid = (float) ($invoice->paid_amount ?? 0);
                $invoiceDue = max(0, $invoiceTotal - $paid);
                $itemDue = $invoiceTotal > 0 ? round($invoiceDue * ($total / $invoiceTotal), 2) : $total;
                return [
                    'id' => $item->id, 'source' => 'sales', 'type' => 'sale',
                    'date' => ($invoice->invoice_date ?? $invoice->created_at)?->toDateString(),
                    'reference' => $invoice->invoice_number, 'invoice_id' => $invoice->id,
                    'customer' => $invoice->customer?->only(['id', 'name', 'name_ar', 'phone']),
                    'warehouse' => $invoice->warehouse?->only(['id', 'name']),
                    'quantity' => (float) ($item->quantity ?? 0), 'unit_price' => (float) ($item->price ?? 0),
                    'total' => $total, 'paid' => max(0, $total - $itemDue), 'due' => $itemDue,
                    'status' => $invoice->status,
                ];
            });

        $pos = $product->invoiceItems()
            ->with(['invoice.customer'])
            ->when($from, fn ($q) => $q->whereHas('invoice', fn ($i) => $i->whereDate('created_at', '>=', $from)))
            ->when($to, fn ($q) => $q->whereHas('invoice', fn ($i) => $i->whereDate('created_at', '<=', $to)))
            ->get()->map(function ($item) {
                $invoice = $item->invoice;
                $total = (float) ($item->total ?? ((float) $item->quantity * (float) $item->price));
                $invoiceTotal = (float) ($invoice->total_amount ?? 0);
                $paid = (float) ($invoice->paid_amount ?? 0);
                $due = $invoiceTotal > 0 ? round(max(0, $invoice->remaining_amount ?? ($invoiceTotal - $paid)) * ($total / $invoiceTotal), 2) : 0;
                return [
                    'id' => $item->id, 'source' => 'pos', 'type' => 'sale',
                    'date' => $invoice->created_at?->toDateString(), 'reference' => $invoice->invoice_number,
                    'invoice_id' => $invoice->id, 'customer' => $invoice->customer?->only(['id', 'name', 'name_ar', 'phone']),
                    'warehouse' => null, 'quantity' => (float) ($item->quantity ?? 0),
                    'unit_price' => (float) ($item->price ?? 0), 'total' => $total,
                    'paid' => max(0, $total - $due), 'due' => $due, 'status' => $invoice->status,
                ];
            });

        $movements = $product->inventoryMovements()->with('warehouse')
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->latest()->get()->map(fn ($movement) => [
                'id' => $movement->id, 'source' => 'inventory', 'type' => $movement->type,
                'date' => $movement->created_at?->toDateString(), 'reference' => $movement->reference_id,
                'reference_type' => $movement->reference_type, 'invoice_id' => null, 'customer' => null,
                'warehouse' => $movement->warehouse?->only(['id', 'name']), 'quantity' => (float) $movement->quantity,
                'unit_cost' => (float) $movement->unit_cost, 'total_cost' => (float) $movement->total_cost,
                'note' => $movement->note,
            ]);

        $salesRows = $sales->concat($pos)->sortByDesc('date')->values();
        return response()->json(['status' => true, 'data' => [
            'product' => $product->only(['id', 'name', 'code', 'stock']),
            'summary' => [
                'sold_quantity' => (float) $salesRows->sum('quantity'),
                'sales_total' => (float) $salesRows->sum('total'),
                'paid_total' => (float) $salesRows->sum('paid'),
                'due_total' => (float) $salesRows->sum('due'),
                'movement_count' => $movements->count(),
            ],
            'sales' => $salesRows,
            'movements' => $movements->values(),
        ]]);
    }
}
