<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\WarehouseRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\WarehouseProductResource;
use App\Http\Resources\WarehouseResource;
use App\Interfaces\WarehouseRepositoryInterface;
use App\Models\InventoryLog;
use App\Models\Warehouse;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseController extends BaseController
{

    protected mixed $crudRepository;

    public function __construct(WarehouseRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    public function index()
    {
        try {
            $warehouse = WarehouseResource::collection($this->crudRepository->all(
                [],
                [],
                ['*']
            ));
            return $warehouse->additional(JsonResponse::success());
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function store(WarehouseRequest $request)
    {
        try {
            $warehouse = $this->crudRepository->create($request->validated());
            return new WarehouseResource($warehouse);
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function show(Warehouse $warehouse): ?\Illuminate\Http\JsonResponse
    {
        try {
            return JsonResponse::respondSuccess('Item Fetched Successfully', new WarehouseResource($warehouse));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function update(WarehouseRequest $request, Warehouse $warehouse)
    {
        try {
            $this->crudRepository->update($request->validated(), $warehouse->id);
            activity()->performedOn($warehouse)->withProperties(['attributes' => $warehouse])->log('update');
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_UPDATED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function destroy(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecords('warehouses', $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function restore(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->restoreItem(Warehouse::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_RESTORED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }




    public function forceDelete(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecordsFinial(Warehouse::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_FORCE_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }



    public function warehouseProducts(Warehouse $warehouse)
    {
        $products = $warehouse->products()
            ->withPivot('stock')
            ->wherePivot('stock', '>', 0)
            ->get();

        return WarehouseProductResource::collection($products);
    }

    public function transfer(Request $request)
    {
        $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', 'exists:warehouses,id'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'products.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ]);
        DB::beginTransaction();

        try {

            $fromWarehouse = Warehouse::findOrFail($request->from_warehouse_id);
            $toWarehouse   = Warehouse::findOrFail($request->to_warehouse_id);

            foreach ($request->products as $item) {

                $productId = $item['product_id'];
                $qty       = $item['quantity'];

                // 🔎 تأكد إن المنتج موجود في مخزن المصدر
                $productInFromWarehouse = $fromWarehouse->products()
                    ->where('products.id', $productId)
                    ->withPivot('stock')
                    ->lockForUpdate()
                    ->first();

                if (!$productInFromWarehouse) {
                    throw new \Exception('المنتج غير موجود في مخزن المصدر');
                }

                $fromPivot = $productInFromWarehouse->pivot;

                // ❌ كمية غير كافية
                if ($fromPivot->stock < $qty) {
                    throw new \Exception('الكمية غير متاحة للتحويل');
                }

                app(\App\Services\InventoryMovementService::class)->apply([
                    'product_id' => $productId, 'warehouse_id' => $fromWarehouse->id,
                    'branch_id' => $fromWarehouse->branch_id, 'movement_type' => 'warehouse_transfer_out',
                    'quantity_delta' => -$qty, 'reference_type' => \App\Models\Warehouse::class,
                    'reference_id' => $toWarehouse->id, 'notes' => "تحويل إلى مخزن {$toWarehouse->name}",
                ]);
                app(\App\Services\InventoryMovementService::class)->apply([
                    'product_id' => $productId, 'warehouse_id' => $toWarehouse->id,
                    'branch_id' => $toWarehouse->branch_id, 'movement_type' => 'warehouse_transfer_in',
                    'quantity_delta' => $qty, 'reference_type' => \App\Models\Warehouse::class,
                    'reference_id' => $fromWarehouse->id, 'notes' => "تحويل من مخزن {$fromWarehouse->name}",
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'تم تحويل المنتجات بنجاح'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'error' => $e->getMessage()
            ], 422);
        }
    }


    public function inventoryStore(Request $request)
    {
        try {
            $validated = $request->validate([
                'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
                'products' => ['required', 'array', 'min:1'],
                'products.*.product_id' => ['required', 'integer'],
                'products.*.counted_stock' => ['required', 'numeric', 'min:0'],
                'note' => ['nullable', 'string', 'max:1000'],
            ]);
            DB::beginTransaction();

            $warehouse = Warehouse::findOrFail($validated['warehouse_id']);

            foreach ($validated['products'] as $item) {

                $productId     = $item['product_id'];
                $countedStock  = $item['counted_stock'];

                // المنتج في المخزن
                $product = $warehouse->products()
                    ->where('products.id', $productId)
                    ->withPivot('stock')
                    ->first();

                if (!$product) {
                    throw new \RuntimeException("المنتج رقم {$productId} غير موجود في المخزن المحدد");
                }

                $systemStock = $product->pivot->stock;
                $difference  = $countedStock - $systemStock;

                $inventoryLog = InventoryLog::create([
                    'warehouse_id'   => $warehouse->id,
                    'product_id'     => $productId,
                    'system_stock'   => $systemStock,
                    'counted_stock'  => $countedStock,
                    'difference'     => $difference,
                    'note'           => $validated['note'] ?? null,
                ]);
                app(\App\Services\InventoryMovementService::class)->apply([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouse->id,
                    'branch_id' => $warehouse->branch_id,
                    'movement_type' => 'inventory_adjustment',
                    'quantity_delta' => $difference,
                    'reference_type' => InventoryLog::class,
                    'reference_id' => $inventoryLog->id,
                    'notes' => $validated['note'] ?? 'تسوية جرد المخزون',
                ]);
                app(\App\Services\InventoryAdjustmentPostingService::class)->post($inventoryLog, (float) ($product->cost ?? 0));
            }

            DB::commit();

            return response()->json(['result' => 'Success', 'message' => 'تم تنفيذ الجرد وتحديث المخزون بنجاح']);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();

            return response()->json([
                'result' => 'Error',
                'message' => app()->isProduction() ? 'تعذر تنفيذ الجرد. لم يتم تعديل المخزون.' : $e->getMessage(),
                'errors' => app()->isProduction() ? [] : ['inventory' => [$e->getMessage()]],
            ], 422);
        }
    }

    public function updateCountedStock(Request $request, InventoryLog $inventoryLog)
    {
        $validated = $request->validate([
            'counted_stock' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:1000',
        ]);
        DB::transaction(function () use ($validated, $inventoryLog): void {
            $warehouse = Warehouse::findOrFail($inventoryLog->warehouse_id);
            if (!$warehouse->products()->whereKey($inventoryLog->product_id)->exists()) {
                throw new \RuntimeException('المنتج غير موجود في المخزن المرتبط بسجل الجرد');
            }
            $product = $warehouse->products()->whereKey($inventoryLog->product_id)->withPivot('stock')->firstOrFail();
            $difference = (float) $validated['counted_stock'] - (float) $product->pivot->stock;
            $inventoryLog->update([
                'counted_stock' => $validated['counted_stock'],
                'note' => $validated['note'] ?? null,
                'difference' => $validated['counted_stock'] - $inventoryLog->system_stock,
            ]);
            app(\App\Services\InventoryMovementService::class)->apply([
                'product_id' => $inventoryLog->product_id,
                'warehouse_id' => $warehouse->id,
                'branch_id' => $warehouse->branch_id,
                'movement_type' => 'inventory_adjustment',
                'quantity_delta' => $difference,
                'reference_type' => InventoryLog::class,
                'reference_id' => $inventoryLog->id,
                'notes' => $validated['note'] ?? 'تعديل تسوية الجرد',
            ]);
            app(\App\Services\InventoryAdjustmentPostingService::class)->post($inventoryLog, (float) ($product->cost ?? 0));
        });

        return response()->json([
            'result' => 'Success',
            'message' => 'Inventory log updated successfully',
            'data' => $inventoryLog,
        ]);
    }


}
