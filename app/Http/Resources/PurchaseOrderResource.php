<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

class PurchaseOrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            
            'supplier_id' => $this->supplier?->id,
            'supplier_name' => $this->supplier?->name,
            
            'expected_delivery' => $this->expected_delivery,
            'total_amount' => (float) $this->total_amount,
            'notes' => $this->notes,
            
            'items' => $this->items->map(function($item){
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    
                    'color_id' => $item->color_id,
                    'color_name' => $item->color?->name,
                    
                    // 'product_variant_id' => $item->product_variant_id,
                    'variant_name' => Schema::hasTable('product_variants') ? $item->variant?->name : null,
                    
                    'quantity' => $item->quantity,
                    'unit_cost' => (float) $item->unit_cost,
                    'total' => (float) $item->total,
                ];
            }),
            
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i'),
        ];
    }
}
