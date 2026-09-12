<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

class PurchaseInvoiceResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,

            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'supplier_name_ar' => $this->supplier?->name_ar,

            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,

            'treasury_id' => $this->treasury_id,
            'treasury_name' => $this->treasury?->name,

            'currency_id' => $this->currency_id,
            'currency_code' => $this->currency?->code,
            
            'tax_id' => $this->tax_id,
            'tax_rate' => $this->tax?->rate,

            'invoice_date' => $this->invoice_date,
            'due_date' => $this->due_date,
            'payment_method' => $this->payment_method,
            'note' => $this->note,
            
            'subtotal' => (float) $this->subtotal,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'total_amount' => (float) $this->total_amount,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,

            'items' => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'product_sku' => $item->product?->sku,
                
                'product_unit_id' => $item->product_unit_id,
                'unit_name' => $item->unit?->name,
                
                'color_id' => $item->color_id,
                'color_name' => $item->color?->name,
                'size_id' => $item->size_id,
                'size_name' => $item->size?->name,
                'product_variant_id' => $item->product_variant_id,
                // The current schema has no product_variants table; variants are represented by color/size.
                'variant_name' => Schema::hasTable('product_variants') ? $item->variant?->name : null,

                'quantity' => $item->quantity,
                'price' => (float) $item->price,
                'discount' => (float) $item->discount,
                'tax' => (float) $item->tax,
                'total' => (float) $item->total,
            ]),

            'created_at' => $this->created_at?->format('Y-m-d H:i'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i'),
        ];
    }
}
