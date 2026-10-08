<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'product_id'   => $this->product_id,
            'product_name' => $this->product_name,
            'item_type'    => $this->item_type ?? 'product',
            'color'        => $this->color,
            'size'         => $this->size,
            'quantity'     => $this->quantity,
            'meter_quantity' => $this->meter_quantity,
            'product_unit_id' => $this->product_unit_id,
            'price'        => $this->price,
            'total'        => $this->total,
            'discount_percentage' => (float) ($this->discount_percentage ?? 0),
            'discount_amount' => (float) ($this->discount_amount ?? 0),
        ];
    }
}
