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
            ->with(['salesInvoice.customer', 'salesInvoice.warehouse', 'unit', 'color'])
            ->when($from, fn ($q) => $q->whereHas('salesInvoice', fn ($i) => $i->whereDate('invoice_date', '>=', $from)))
            ->when($to, fn ($q) => $q->whereHas('salesInvoice', fn ($i) => $i->whereDate('invoice_date', '<=', $to)))
            ->get()->map(function ($item) {
                $invoice = $item->salesInvoice;
                $total = (float) ($item->total ?? ((float) $item->quantity * (float) $item->price));
                $invoiceTotal = (float) ($invoice?->net_total ?? $invoice?->total_amount ?? 0);
                $paid = (float) ($invoice?->paid_amount ?? 0);
                $invoiceDue = max(0, $invoiceTotal - $paid);
                $itemDue = $invoiceTotal > 0 ? round($invoiceDue * ($total / $invoiceTotal), 2) : $total;
                return [
                    'id' => $item->id, 'source' => 'sales', 'type' => 'sale',
                    'date' => ($invoice?->invoice_date ?? $invoice?->created_at ?? $item->created_at)?->toDateString(),
                    'reference' => $invoice?->invoice_number, 'invoice_id' => $invoice?->id,
                    'customer' => $invoice?->customer?->only(['id', 'name', 'name_ar', 'phone']),
                    'warehouse' => $invoice?->warehouse?->only(['id', 'name']),
                    'variant' => [
                        'product_unit_id' => $item->product_unit_id,
                        'size' => data_get($item->unit, 'name') ?? data_get($item->unit, 'name_ar') ?? (is_string($item->unit) ? $item->unit : null),
                        'color_id' => $item->color_id,
                        'color' => data_get($item->color, 'name') ?? data_get($item->color, 'name_ar') ?? (is_string($item->color) ? $item->color : null),
                    ],
                    'quantity' => (float) ($item->quantity ?? 0), 'unit_price' => (float) ($item->price ?? 0),
                    'total' => $total, 'paid' => max(0, $total - $itemDue), 'due' => $itemDue,
                    'status' => $invoice?->status,
                ];
            });

        $pos = $product->invoiceItems()
            ->with(['invoice.customer', 'unit', 'color'])
            ->when($from, fn ($q) => $q->whereHas('invoice', fn ($i) => $i->whereDate('created_at', '>=', $from)))
            ->when($to, fn ($q) => $q->whereHas('invoice', fn ($i) => $i->whereDate('created_at', '<=', $to)))
            ->get()->map(function ($item) {
                $invoice = $item->invoice;
                $total = (float) ($item->total ?? ((float) $item->quantity * (float) $item->price));
                $invoiceTotal = (float) ($invoice?->total_amount ?? 0);
                $paid = (float) ($invoice?->paid_amount ?? 0);
                $due = $invoiceTotal > 0 ? round(max(0, $invoice?->remaining_amount ?? ($invoiceTotal - $paid)) * ($total / $invoiceTotal), 2) : 0;
                return [
                    'id' => $item->id, 'source' => 'pos', 'type' => 'sale',
                    'date' => ($invoice?->created_at ?? $item->created_at)?->toDateString(), 'reference' => $invoice?->invoice_number,
                    'invoice_id' => $invoice?->id, 'customer' => $invoice?->customer?->only(['id', 'name', 'name_ar', 'phone']),
                    'warehouse' => null,
                    'variant' => [
                        'product_unit_id' => $item->product_unit_id,
                        'size' => data_get($item->unit, 'name') ?? data_get($item->unit, 'name_ar') ?? (is_string($item->unit) ? $item->unit : null),
                        'color_id' => $item->color_id,
                        'color' => data_get($item->color, 'name') ?? data_get($item->color, 'name_ar') ?? (is_string($item->color) ? $item->color : null),
                    ],
                    'quantity' => (float) ($item->quantity ?? 0),
                    'unit_price' => (float) ($item->price ?? 0), 'total' => $total,
                    'paid' => max(0, $total - $due), 'due' => $due, 'status' => $invoice?->status,
                ];
            });

        $purchases = $product->purchaseInvoiceItems()
            ->with(['purchaseInvoice.supplier', 'purchaseInvoice.warehouse', 'unit', 'color'])
            ->when($from, fn ($q) => $q->whereHas('purchaseInvoice', fn ($i) => $i->whereDate('invoice_date', '>=', $from)))
            ->when($to, fn ($q) => $q->whereHas('purchaseInvoice', fn ($i) => $i->whereDate('invoice_date', '<=', $to)))
            ->get()->map(function ($item) {
                $invoice = $item->purchaseInvoice;
                $total = (float) ($item->total_price ?? $item->total ?? ((float) $item->quantity * (float) ($item->unit_price ?? $item->price)));
                return [
                    'id' => $item->id, 'source' => 'purchase', 'type' => 'purchase',
                    'date' => ($invoice?->invoice_date ?? $invoice?->created_at ?? $item->created_at)?->toDateString(),
                    'reference' => $invoice?->invoice_number ?? $invoice?->id, 'invoice_id' => $invoice?->id,
                    'supplier' => $invoice?->supplier?->only(['id', 'name', 'name_ar', 'phone']),
                    'warehouse' => $invoice?->warehouse?->only(['id', 'name']),
                    'variant' => [
                        'product_unit_id' => $item->product_unit_id,
                        'size' => data_get($item->unit, 'name') ?? data_get($item->unit, 'name_ar') ?? (is_string($item->unit) ? $item->unit : null),
                        'color_id' => $item->color_id,
                        'color' => data_get($item->color, 'name') ?? data_get($item->color, 'name_ar') ?? (is_string($item->color) ? $item->color : null),
                    ],
                    'quantity' => (float) ($item->quantity ?? 0),
                    'unit_price' => (float) ($item->unit_price ?? $item->price ?? 0),
                    'total' => $total, 'status' => $invoice?->status,
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
        $purchaseRows = $purchases->sortByDesc('date')->values();
        return response()->json(['status' => true, 'data' => [
            'product' => $product->only(['id', 'name', 'code', 'stock']),
            'summary' => [
                'sold_quantity' => (float) $salesRows->sum('quantity'),
                'purchased_quantity' => (float) $purchaseRows->sum('quantity'),
                'sales_total' => (float) $salesRows->sum('total'),
                'purchases_total' => (float) $purchaseRows->sum('total'),
                'paid_total' => (float) $salesRows->sum('paid'),
                'due_total' => (float) $salesRows->sum('due'),
                'movement_count' => $movements->count(),
            ],
            'sales' => $salesRows,
            'purchases' => $purchaseRows,
            'movements' => $movements->values(),
        ]]);
    }
}
