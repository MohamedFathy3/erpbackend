<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'description'   => $this->description,
            'image_url'     => $this->image_url,

            'imageUrl' => $this->getFirstMediaUrlTeam(),
            'image'    => new MediaResource($this->getFirstMedia()),

            'category'      => new CategoryResource($this->category),

            'sku'           => $this->sku,
            'barcode'       => $this->barcode,
            'beginning_balance' => $this->beginning_balance,

            // 👇 الكمية من المخزن
            'stock'         => $this->stock ?? 0,

            'reorder_level' => $this->reorder_level,
            'price'         => $this->price,
            'cost'          => $this->cost,
            'active'        => $this->active,
       // ✅ المخازن المرتبطة بالمنتج
            'warehouse_ids' => $this->whenLoaded('warehouses', fn () =>
                $this->warehouses->pluck('id')->values()
            ),
            'warehouses' => $this->whenLoaded('warehouses', fn () =>
                $this->warehouses->map(fn ($w) => [
                    'id'    => $w->id,
                    'stock' => $w->pivot->stock,
                ])->values()
            ),

            'units' => ProductUnitResource::collection(
                $this->units
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'automotive_service' => $this->whenLoaded('automotiveService', fn () => $this->automotiveService ? [
                'id' => $this->automotiveService->id,
                'item_type' => $this->automotiveService->item_type,
                'unit' => $this->automotiveService->unit,
                'selling_price' => $this->automotiveService->selling_price,
                'estimated_cost' => $this->automotiveService->estimated_cost,
                'small_vehicle_quantity' => $this->automotiveService->small_vehicle_quantity,
                'large_vehicle_quantity' => $this->automotiveService->large_vehicle_quantity,
                'small_vehicle_price' => $this->automotiveService->small_vehicle_price,
                'large_vehicle_price' => $this->automotiveService->large_vehicle_price,
                'stock_quantity' => $this->automotiveService->stock_quantity,
            ] : null),
        ];
    }
}
