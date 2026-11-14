<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnItemResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,

            'product' => $this->product_id ? [
                'id'   => $this->product_id,
                'name' => $this->product_name,
            ] : null,
            'invoice_item_id' => $this->invoice_item_id,
            'automotive_service_id' => $this->automotive_service_id,
            'automotive_service' => $this->automotive_service_id ? [
                'id' => $this->automotive_service_id,
                'name' => $this->product_name,
            ] : null,

            'color' => $this->color,
            'size'  => $this->size,

            'quantity' => $this->quantity,

            'price' => (float) $this->price,
            'total' => (float) $this->total,

            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
