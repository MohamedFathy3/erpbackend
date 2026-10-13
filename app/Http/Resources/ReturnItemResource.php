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

            'product' => $this->product ? [
                'id'   => $this->product_id,
                'name' => $this->product_name ?: $this->product->name,
            ] : null,
            'service' => $this->automotiveService ? [
                'id' => $this->automotive_service_id,
                'name' => $this->product_name ?: $this->automotiveService->name,
            ] : null,

            'color' => $this->color,
            'size'  => $this->size,

            'quantity' => $this->quantity,
            'meter_quantity' => $this->meter_quantity,

            'price' => (float) $this->price,
            'total' => (float) $this->total,

            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
