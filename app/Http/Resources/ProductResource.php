<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $branchId = (int) ($request->user()?->branch_id
            ?: (app()->bound('currentBranchId') ? app('currentBranchId') : 0));
        $branchPrice = $this->relationLoaded('branchPrices')
            ? $this->branchPrices->firstWhere('branch_id', $branchId)
            : null;
        $displayPrice = $this->display_price;
        $branchPriceValue = $branchPrice?->price;
        $effectivePrice = $displayPrice !== null && (float) $displayPrice > 0
            ? (float) $displayPrice
            : (($branchPriceValue !== null && (float) $branchPriceValue > 0)
                ? (float) $branchPriceValue
                : (float) ($this->price ?? 0));

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

            // The selected warehouse balance is set by ProductController when
            // warehouse_id is supplied; otherwise retain the aggregate master stock.
            'stock'         => $this->display_stock ?? $this->stock ?? 0,

            'reorder_level' => $this->reorder_level,
            'price'         => $effectivePrice,
            'base_price'    => $this->price,
            'branch_prices' => $this->whenLoaded('branchPrices', fn () => $this->branchPrices->map(fn ($branchPrice) => [
                'branch_id' => (int) $branchPrice->branch_id,
                'price' => (float) $branchPrice->price,
            ])->values()),
            'cost'          => $this->cost,
            'active'        => $this->active,
            'product_type'  => $this->product_type ?? 'good',
            'package_size'  => $this->package_size,
            'package_unit'  => $this->package_unit,
            'package_cost'  => $this->package_cost,
            'stock_unit'    => $this->stock_unit,
            'base_unit'     => $this->base_unit,
            'conversion_factor' => $this->conversion_factor,
            'tracking'      => $this->tracking ?? 'none',
            'dimensions'    => $this->dimensions,
            'prices'        => $this->whenLoaded('prices', fn () => $this->prices->map(fn ($price) => [
                'id' => $price->id,
                'price_type' => $price->price_type,
                'min_quantity' => (float) $price->min_quantity,
                'price' => (float) $price->price,
                'unit_id' => $price->unit_id,
                'unit_name' => $price->unit?->name,
                'color_id' => $price->color_id,
                'color_name' => $price->color?->name,
            ])->values()),
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
