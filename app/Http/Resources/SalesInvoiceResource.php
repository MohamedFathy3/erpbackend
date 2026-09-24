<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesInvoiceResource extends JsonResource
{
    public function toArray($request)
    {
        /*
        |--------------------------------------------------------------------------
        | المرتجعات الصحيحة فقط
        |--------------------------------------------------------------------------
        | نستبعد المرتجعات الملغاة.
        |
        */

        $returns = $this->relationLoaded('returns')
            ? $this->returns
            : $this->returns()
                ->with('items')
                ->get();

        $validReturns = $returns->filter(function ($return) {
            return $return->workflow_status !== 'cancelled';
        });

        /*
        |--------------------------------------------------------------------------
        | إجمالي مبلغ المرتجعات
        |--------------------------------------------------------------------------
        */

        $returnedAmount = (float) $validReturns->sum(function ($return) {
            return (float) ($return->total_amount ?? 0);
        });

        /*
        |--------------------------------------------------------------------------
        | إجمالي الفاتورة
        |--------------------------------------------------------------------------
        */

        $totalAmount = (float) ($this->net_total ?? $this->total_amount ?? 0);

        /*
        |--------------------------------------------------------------------------
        | المبلغ المتبقي القابل للمرتجع
        |--------------------------------------------------------------------------
        */

        $remainingReturnAmount = max(
            0,
            $totalAmount - $returnedAmount
        );

        /*
        |--------------------------------------------------------------------------
        | حالة المرتجع
        |--------------------------------------------------------------------------
        */

        if ($returnedAmount <= 0) {

            $returnStatus = 'none';
            $returnStatusLabel = 'لم يتم الإرجاع';

        } elseif ($returnedAmount >= $totalAmount) {

            $returnStatus = 'full';
            $returnStatusLabel = 'مرتجع كامل';

        } else {

            $returnStatus = 'partial';
            $returnStatusLabel = 'مرتجع جزئي';
        }

        /*
        |--------------------------------------------------------------------------
        | المدفوع
        |--------------------------------------------------------------------------
        */

        $paidAmount = (float) ($this->paid_amount ?? 0);

        /*
        |--------------------------------------------------------------------------
        | المتبقي الأصلي قبل احتساب المرتجع
        |--------------------------------------------------------------------------
        */

        $originalRemainingAmount = max(
            0,
            $totalAmount - $paidAmount
        );

        /*
        |--------------------------------------------------------------------------
        | المتبقي بعد المرتجع
        |--------------------------------------------------------------------------
        |
        | مثال:
        |
        | الفاتورة = 5000
        | المدفوع = 2300
        | المرتجع = 2000
        |
        | المتبقي = 5000 - 2300 - 2000 = 700
        |
        */

        $remainingAmountAfterReturn = max(
            0,
            $totalAmount - $paidAmount - $returnedAmount
        );

        /*
        |--------------------------------------------------------------------------
        | إجمالي المبالغ التي تم إرجاعها لكل منتج
        |--------------------------------------------------------------------------
        */

        $returnedByProduct = [];

        foreach ($validReturns as $return) {

            foreach ($return->items as $returnItem) {

                $productId = $returnItem->product_id;

                if (!isset($returnedByProduct[$productId])) {
                    $returnedByProduct[$productId] = [
                        'quantity' => 0,
                        'amount' => 0,
                    ];
                }

                $returnedByProduct[$productId]['quantity'] +=
                    (float) $returnItem->quantity;

                $returnedByProduct[$productId]['amount'] +=
                    (float) ($returnItem->total ?? 0);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | المنتجات التي تم إرجاعها
        |--------------------------------------------------------------------------
        */

        $returnedProducts = $this->items
            ->filter(function ($item) use ($returnedByProduct) {

                return isset($returnedByProduct[$item->product_id])
                    && $returnedByProduct[$item->product_id]['quantity'] > 0;
            })
            ->map(function ($item) use ($returnedByProduct) {

                $returnedQuantity =
                    $returnedByProduct[$item->product_id]['quantity'];

                $returnedAmount =
                    $returnedByProduct[$item->product_id]['amount'];

                $originalQuantity = (float) $item->quantity;

                $remainingQuantity = max(
                    0,
                    $originalQuantity - $returnedQuantity
                );

                if ($returnedQuantity >= $originalQuantity) {
                    $itemReturnStatus = 'full';
                    $itemReturnStatusLabel = 'مرتجع كامل';
                } elseif ($returnedQuantity > 0) {
                    $itemReturnStatus = 'partial';
                    $itemReturnStatusLabel = 'مرتجع جزئي';
                } else {
                    $itemReturnStatus = 'none';
                    $itemReturnStatusLabel = 'لم يتم الإرجاع';
                }

                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,

                    'product_unit_id' => $item->product_unit_id,
                    'unit_name' => $item->unit?->name,

                    'color_id' => $item->color_id,
                    'color_name' => $item->color?->name,

                    'size_id' => $item->size_id,
                    'size_name' => $item->size?->name,

                    'product_variant_id' => $item->product_variant_id,

                    'original_quantity' => $originalQuantity,

                    'returned_quantity' => $returnedQuantity,

                    'remaining_quantity' => $remainingQuantity,

                    'returned_amount' => number_format(
                        $returnedAmount,
                        2,
                        '.',
                        ''
                    ),

                    'return_status' => $itemReturnStatus,

                    'return_status_label' => $itemReturnStatusLabel,
                ];
            })
            ->values();

        return [

            /*
            |--------------------------------------------------------------------------
            | Basic
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            'invoice_number' => $this->invoice_number,

            /*
            |--------------------------------------------------------------------------
            | Customer
            |--------------------------------------------------------------------------
            */

            'customer' => [
                'id' => $this->customer?->id,
                'name' => $this->customer?->name,
            ],

            /*
            |--------------------------------------------------------------------------
            | Sales Representative
            |--------------------------------------------------------------------------
            */

            'sales_representative' => [
                'id' => $this->salesRepresentative?->id,
                'name' => $this->salesRepresentative?->name,
            ],

            /*
            |--------------------------------------------------------------------------
            | Relations
            |--------------------------------------------------------------------------
            */

            'treasury' => $this->treasury?->name,
            'treasury_id' => $this->treasury_id,

            'bank' => $this->bank
                ? [
                    'id' => $this->bank->id,
                    'name' => $this->bank->name,
                    'balance' => $this->bank->balance,
                ]
                : null,

            'bank_id' => $this->bank_id,

            'branch' => $this->branch?->name,

            'warehouse' => $this->warehouse?->name,

            'currency' => $this->currency?->code,

            'tax' => $this->tax?->name,

            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_method' => $this->payment_method,

            'payment_status' => $this->payment_status,

            /*
            |--------------------------------------------------------------------------
            | السداد
            |--------------------------------------------------------------------------
            */

            'paid_amount' => number_format(
                $paidAmount,
                2,
                '.',
                ''
            ),

            'original_remaining_amount' => number_format(
                $originalRemainingAmount,
                2,
                '.',
                ''
            ),

            /*
            |--------------------------------------------------------------------------
            | المرتجع
            |--------------------------------------------------------------------------
            */

            'return_status' => $returnStatus,

            'return_status_label' => $returnStatusLabel,

            'returned_amount' => number_format(
                $returnedAmount,
                2,
                '.',
                ''
            ),

            'remaining_return_amount' => number_format(
                $remainingReturnAmount,
                2,
                '.',
                ''
            ),

            /*
            |--------------------------------------------------------------------------
            | المبلغ المتبقي بعد المرتجع والسداد
            |--------------------------------------------------------------------------
            */

            'remaining_amount' => number_format(
                $remainingAmountAfterReturn,
                2,
                '.',
                ''
            ),

            /*
            |--------------------------------------------------------------------------
            | Workflow
            |--------------------------------------------------------------------------
            */

            'workflow_status' => $this->workflow_status,

            'posting_journal_entry_id' =>
                $this->posting_journal_entry_id,

            'cogs_journal_entry_id' => $this->cogs_journal_entry_id,

            'commission_journal_entry_id' => $this->commission_journal_entry_id,

            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            'invoice_date' => $this->invoice_date,

            'due_date' => $this->due_date,

            'note' => $this->note,

            /*
            |--------------------------------------------------------------------------
            | Totals
            |--------------------------------------------------------------------------
            */

            'total_amount' => number_format(
                $this->total_amount,
                2,
                '.',
                ''
            ),

            'discount_percentage' => number_format(
                $this->discount_percentage ?? 0,
                2,
                '.',
                ''
            ),

            'discount_amount' => number_format(
                $this->discount_amount ?? 0,
                2,
                '.',
                ''
            ),

            'net_total' => number_format(
                $this->net_total ?? 0,
                2,
                '.',
                ''
            ),

            /*
            |--------------------------------------------------------------------------
            | Items
            |--------------------------------------------------------------------------
            */

            'items' => $this->items->map(function ($item) use ($returnedByProduct) {

                $originalPrice =
                    (float) $item->price *
                    (float) $item->quantity;

                $discountAmount =
                    $originalPrice -
                    (float) $item->total;

                $discountPercentage =
                    $originalPrice > 0
                        ? ($discountAmount / $originalPrice) * 100
                        : 0;

                $returnedQuantity =
                    $returnedByProduct[$item->product_id]['quantity']
                    ?? 0;

                $remainingQuantity = max(
                    0,
                    (float) $item->quantity - $returnedQuantity
                );

                if ($returnedQuantity <= 0) {

                    $itemReturnStatus = 'none';
                    $itemReturnStatusLabel = 'لم يتم الإرجاع';

                } elseif ($returnedQuantity >= (float) $item->quantity) {

                    $itemReturnStatus = 'full';
                    $itemReturnStatusLabel = 'مرتجع كامل';

                } else {

                    $itemReturnStatus = 'partial';
                    $itemReturnStatusLabel = 'مرتجع جزئي';
                }

                return [

                    'product_id' => $item->product_id,

                    'product_name' => $item->product?->name,

                    'product_unit_id' => $item->product_unit_id,

                    'unit_name' => $item->unit?->name,

                    'color_id' => $item->color_id,

                    'color_name' => $item->color?->name,

                    'size_id' => $item->size_id,

                    'size_name' => $item->size?->name,

                    'product_variant_id' =>
                        $item->product_variant_id,

                    /*
                    | الكميات
                    */

                    'quantity' => $item->quantity,

                    'returned_quantity' => $returnedQuantity,

                    'remaining_quantity' => $remainingQuantity,

                    /*
                    | حالة المنتج
                    */

                    'return_status' => $itemReturnStatus,

                    'return_status_label' =>
                        $itemReturnStatusLabel,

                    /*
                    | السعر
                    */

                    'price' => number_format(
                        $item->price,
                        2,
                        '.',
                        ''
                    ),

                    /*
                    | Discount
                    */

                    'discount_percentage' => number_format(
                        $item->discount_percentage > 0
                            ? $item->discount_percentage
                            : $discountPercentage,
                        2,
                        '.',
                        ''
                    ),

                    'discount_amount' => number_format(
                        $item->discount_amount > 0
                            ? $item->discount_amount
                            : $discountAmount,
                        2,
                        '.',
                        ''
                    ),

                    'total' => number_format(
                        $item->total,
                        2,
                        '.',
                        ''
                    ),
                ];
            }),

            /*
            |--------------------------------------------------------------------------
            | المنتجات المرتجعة فقط
            |--------------------------------------------------------------------------
            */

            'returned_products' => $returnedProducts,

            /*
            |--------------------------------------------------------------------------
            | تاريخ الإنشاء
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at
                ? $this->created_at->format('Y-m-d H:i')
                : null,
        ];
    }
}
