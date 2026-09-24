<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

class PurchaseInvoiceResource extends JsonResource
{
    public function toArray($request)
    {
        /*
        |--------------------------------------------------------------------------
        | Purchase Returns
        |--------------------------------------------------------------------------
        */

        $returns = $this->relationLoaded('returns')
            ? $this->returns->where('workflow_status', '!=', 'cancelled')
            : $this->returns()
                ->where('workflow_status', '!=', 'cancelled')
                ->with('items')
                ->get();

        /*
        |--------------------------------------------------------------------------
        | إجمالي قيمة المرتجعات
        |--------------------------------------------------------------------------
        */

        $returnedAmount = (float) $returns->sum('total_amount');

        /*
        |--------------------------------------------------------------------------
        | حساب الكميات المرتجعة لكل منتج
        |--------------------------------------------------------------------------
        */

        $returnedItems = [];

        foreach ($returns as $return) {

            foreach ($return->items as $returnItem) {

                $key =
                    $returnItem->product_id . '-' .
                    ($returnItem->color_id ?? 'null');

                if (!isset($returnedItems[$key])) {
                    $returnedItems[$key] = 0;
                }

                $returnedItems[$key] += (float) $returnItem->quantity;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | تحديد حالة المرتجع للفاتورة
        |--------------------------------------------------------------------------
        */

        $hasReturn = false;
        $isFullReturn = true;

        foreach ($this->items as $invoiceItem) {

            $key =
                $invoiceItem->product_id . '-' .
                ($invoiceItem->color_id ?? 'null');

            $returnedQuantity = (float) (
                $returnedItems[$key] ?? 0
            );

            $originalQuantity = (float) $invoiceItem->quantity;

            /*
            | يوجد مرتجع
            */

            if ($returnedQuantity > 0) {
                $hasReturn = true;
            }

            /*
            | لو الكمية المرتجعة أقل من الأصلية
            | إذن المرتجع جزئي
            */

            if ($returnedQuantity < $originalQuantity) {
                $isFullReturn = false;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | حالة المرتجع
        |--------------------------------------------------------------------------
        */

        $returnStatus = 'none';
        $returnStatusLabel = 'لا يوجد مرتجع';

        if ($hasReturn && $isFullReturn) {

            $returnStatus = 'full';
            $returnStatusLabel = 'مرتجع كامل';

        } elseif ($hasReturn) {

            $returnStatus = 'partial';
            $returnStatusLabel = 'مرتجع جزئي';
        }

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            'invoice_number' => $this->invoice_number,

            /*
            |--------------------------------------------------------------------------
            | Return Information
            |--------------------------------------------------------------------------
            */

            'return_status' => $returnStatus,

            'return_status_label' => $returnStatusLabel,

            'returned_amount' => round($returnedAmount, 2),

            /*
            |--------------------------------------------------------------------------
            | Supplier
            |--------------------------------------------------------------------------
            */

            'supplier_id' => $this->supplier_id,

            'supplier_name' => $this->supplier?->name,

            'supplier_name_ar' => $this->supplier?->name_ar,

            /*
            |--------------------------------------------------------------------------
            | Branch
            |--------------------------------------------------------------------------
            */

            'branch_id' => $this->branch_id,

            'branch_name' => $this->branch?->name,

            /*
            |--------------------------------------------------------------------------
            | Warehouse
            |--------------------------------------------------------------------------
            */

            'warehouse_id' => $this->warehouse_id,

            'warehouse_name' => $this->warehouse?->name,

            /*
            |--------------------------------------------------------------------------
            | Treasury
            |--------------------------------------------------------------------------
            */

            'treasury_id' => $this->treasury_id,

            'treasury_name' => $this->treasury?->name,

            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            'currency_id' => $this->currency_id,

            'currency_code' => $this->currency?->code,

            /*
            |--------------------------------------------------------------------------
            | Tax
            |--------------------------------------------------------------------------
            */

            'tax_id' => $this->tax_id,

            'tax_rate' => $this->tax?->rate,

            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            'invoice_date' => $this->invoice_date,

            'due_date' => $this->due_date,

            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_method' => $this->payment_method,

            'note' => $this->note,

            /*
            |--------------------------------------------------------------------------
            | Amounts
            |--------------------------------------------------------------------------
            */

            'subtotal' => (float) $this->subtotal,

            'discount_total' => (float) $this->discount_total,

            'tax_total' => (float) $this->tax_total,

            'total_amount' => (float) $this->total_amount,

            'paid_amount' => (float) $this->paid_amount,

            'remaining_amount' => (float) $this->remaining_amount,

            'posting_journal_entry_id' => $this->posting_journal_entry_id,

            'workflow_status' => $this->workflow_status,

            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'purchase_invoice_id' => $payment->purchase_invoice_id,
                'treasury_id' => $payment->treasury_id,
                'bank_id' => $payment->bank_id,
                'amount' => (float) $payment->amount,
                'payment_date' => $payment->payment_date?->toDateString(),
                'payment_method' => $payment->payment_method,
                'created_by' => $payment->created_by,
                'created_by_type' => $payment->created_by_type,
                'journal_entry_id' => $payment->journal_entry_id,
                'notes' => $payment->notes,
            ])),

            /*
            |--------------------------------------------------------------------------
            | Items
            |--------------------------------------------------------------------------
            */

            'items' => $this->items->map(function ($item) use ($returnedItems) {

                /*
                |--------------------------------------------------------------------------
                | مفتاح المنتج
                |--------------------------------------------------------------------------
                */

                $key =
                    $item->product_id . '-' .
                    ($item->color_id ?? 'null');

                /*
                |--------------------------------------------------------------------------
                | الكمية المرتجعة
                |--------------------------------------------------------------------------
                */

                $returnedQuantity = (float) (
                    $returnedItems[$key] ?? 0
                );

                /*
                |--------------------------------------------------------------------------
                | الكمية الأصلية
                |--------------------------------------------------------------------------
                */

                $originalQuantity = (float) $item->quantity;

                /*
                |--------------------------------------------------------------------------
                | الكمية المتبقية
                |--------------------------------------------------------------------------
                */

                $remainingQuantity = max(
                    0,
                    $originalQuantity - $returnedQuantity
                );

                /*
                |--------------------------------------------------------------------------
                | حالة مرتجع المنتج
                |--------------------------------------------------------------------------
                */

                $itemReturnStatus = 'none';

                $itemReturnStatusLabel = 'لا يوجد مرتجع';

                if (
                    $returnedQuantity > 0 &&
                    $remainingQuantity > 0
                ) {

                    $itemReturnStatus = 'partial';

                    $itemReturnStatusLabel = 'مرتجع جزئي';

                } elseif (
                    $returnedQuantity > 0 &&
                    $remainingQuantity <= 0
                ) {

                    $itemReturnStatus = 'full';

                    $itemReturnStatusLabel = 'مرتجع كامل';
                }

                return [

                    /*
                    |--------------------------------------------------------------------------
                    | Product
                    |--------------------------------------------------------------------------
                    */

                    'product_id' => $item->product_id,

                    'product_name' => $item->product?->name,

                    'product_sku' => $item->product?->sku,

                    /*
                    |--------------------------------------------------------------------------
                    | Unit
                    |--------------------------------------------------------------------------
                    */

                    'product_unit_id' => $item->product_unit_id,

                    'unit_name' => $item->unit?->name,

                    /*
                    |--------------------------------------------------------------------------
                    | Color
                    |--------------------------------------------------------------------------
                    */

                    'color_id' => $item->color_id,

                    'color_name' => $item->color?->name,

                    /*
                    |--------------------------------------------------------------------------
                    | Size
                    |--------------------------------------------------------------------------
                    */

                    'size_id' => $item->size_id,

                    'size_name' => $item->size?->name,

                    /*
                    |--------------------------------------------------------------------------
                    | Variant
                    |--------------------------------------------------------------------------
                    */

                    'product_variant_id' => $item->product_variant_id,

                    'variant_name' => Schema::hasTable('product_variants')
                        ? $item->variant?->name
                        : null,

                    /*
                    |--------------------------------------------------------------------------
                    | Original Quantity
                    |--------------------------------------------------------------------------
                    */

                    'quantity' => $originalQuantity,

                    /*
                    |--------------------------------------------------------------------------
                    | Return Information
                    |--------------------------------------------------------------------------
                    */

                    'returned_quantity' => $returnedQuantity,

                    'remaining_quantity' => $remainingQuantity,

                    'return_status' => $itemReturnStatus,

                    'return_status_label' => $itemReturnStatusLabel,

                    /*
                    |--------------------------------------------------------------------------
                    | Price
                    |--------------------------------------------------------------------------
                    */

                    'price' => (float) $item->price,

                    'discount' => (float) $item->discount,

                    'tax' => (float) $item->tax,

                    'total' => (float) $item->total,
                ];
            }),

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->format('Y-m-d H:i'),

            'updated_at' => $this->updated_at?->format('Y-m-d H:i'),
        ];
    }
}
