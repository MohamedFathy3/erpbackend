<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\ProductRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Imports\ProductImport;
use App\Interfaces\ProductRepositoryInterface;
use App\Models\Category;
use App\Models\Product;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\InvoiceItem;
use App\Models\ProductUnit;
use App\Models\ProductUnitColor;
use App\Models\ProductWarehouse;

class ProductController extends BaseController
{

    protected mixed $crudRepository;

    public function __construct(ProductRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

  public function index(Request $request)
{
    try {
        $filters = $request->input('filters', []);

        $query = Product::query()->with(['category', 'units.colors', 'warehouses', 'automotiveService']);

        // فلتر المخزن
        if (!empty($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];
            $query->whereHas('warehouses', function ($q) use ($warehouseId) {
                $q->where('warehouses.id', $warehouseId);
            });
        }

        // فلتر التصنيف
        if (!empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        // فلتر الحالة (active / inactive)
        if (array_key_exists('active', $filters)) {
            $query->where('active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('beginning_balance', $filters)) {
            $query->where('beginning_balance', filter_var($filters['beginning_balance'], FILTER_VALIDATE_BOOLEAN));
        }

        $orderBy = $request->input('orderBy', 'id');
        $direction = strtolower($request->input('orderByDirection', 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($orderBy, $direction);

        $products = $query->get();

        return ProductResource::collection($products)
            ->additional(JsonResponse::success());
    } catch (Exception $e) {
        return JsonResponse::respondError($e->getMessage());
    }
}

public function show(Product $product): ?\Illuminate\Http\JsonResponse
{
    try {
        return JsonResponse::respondSuccess(
            'Item Fetched Successfully',
            new ProductResource($product->load('warehouses'))
        );
    } catch (Exception $e) {
        return JsonResponse::respondError($e->getMessage());
    }
}


   public function store(ProductRequest $request)
{
    DB::beginTransaction();

    try {
        $data = $request->validated();

        $product = $this->crudRepository->create(
    collect($data)->except(['units', 'warehouse_ids', 'branch_ids'])->toArray()
);

        if (!empty($data['units']) && is_array($data['units'])) {
            // إعادة تنظيم الوحدات
            $organizedUnits = [];

            foreach ($data['units'] as $unitData) {
                $unitId = $unitData['unit_id'];

                if (!isset($organizedUnits[$unitId])) {
                    // أول مرة نشوف فيها الـ unit_id
                    $organizedUnits[$unitId] = [
                        'unit_id' => $unitId,
                        'cost_price' => $unitData['cost_price'],
                        'sell_price' => $unitData['sell_price'],
                        'barcode' => $unitData['barcode'] ?? null,
                        'colors' => []
                    ];
                }

                // إضافة الألوان
                if (!empty($unitData['colors'])) {
                    foreach ($unitData['colors'] as $colorData) {
                        $organizedUnits[$unitId]['colors'][] = $colorData;
                    }
                }
            }

            // إدخال الوحدات المنظمة
            foreach ($organizedUnits as $unitData) {
                $productUnit = $product->units()->create([
                    'unit_id'     => $unitData['unit_id'],
                    'cost_price'  => $unitData['cost_price'],
                    'sell_price'  => $unitData['sell_price'],
                    'barcode'     => $unitData['barcode'] ?? null,
                ]);

                if (!empty($unitData['colors'])) {
                    foreach ($unitData['colors'] as $colorData) {
                        $productUnit->colors()->create([
                            'color_id' => $colorData['color_id'],
                            'stock'    => $colorData['stock'],
                        ]);
                    }
                }
            }
        }
$this->linkWarehouses($product, $data['warehouse_ids'] ?? null);
        if (request('image') !== null) {
            $this->crudRepository->AddMediaCollection('image', $product);
        }

        DB::commit();

        return new ProductResource($product->load('units.colors'));

    } catch (Exception $e) {
        DB::rollBack();
        return JsonResponse::respondError($e->getMessage());
    }
}



    

public function update(ProductUpdateRequest $request, Product $product)
{
    DB::beginTransaction();

    try {
        $data = $request->validated();

  $this->crudRepository->update(
    collect($data)->except(['units', 'image', 'warehouse_ids', 'branch_ids'])->toArray(),
    $product->id
);

$this->linkWarehouses($product, $data['warehouse_ids'] ?? null);

        if ($request->has('image') && $request->input('image') !== null) {
            $this->crudRepository->AddMediaCollection('image', $product);
        }

        if (!empty($data['units']) && is_array($data['units'])) {
            // تنظيم الوحدات وإزالة الألوان المكررة
            $organizedUnits = [];

            foreach ($data['units'] as $unitData) {
                $unitId = $unitData['unit_id'];

                if (!isset($organizedUnits[$unitId])) {
                    $organizedUnits[$unitId] = [
                        'unit_id' => $unitId,
                        'cost_price' => $unitData['cost_price'],
                        'sell_price' => $unitData['sell_price'],
                        'barcode' => $unitData['barcode'] ?? null,
                        'colors' => []
                    ];
                }

                // إزالة الألوان المكررة
                if (!empty($unitData['colors'])) {
                    foreach ($unitData['colors'] as $colorData) {
                        $colorExists = false;
                        foreach ($organizedUnits[$unitId]['colors'] as $existingColor) {
                            if ($existingColor['color_id'] == $colorData['color_id']) {
                                $colorExists = true;
                                break;
                            }
                        }

                        if (!$colorExists) {
                            $organizedUnits[$unitId]['colors'][] = $colorData;
                        }
                    }
                }
            }

            // الحصول على الـ unit_ids الموجودة حالياً
            $existingUnitIds = $product->units()->pluck('unit_id')->toArray();
            $newUnitIds = array_keys($organizedUnits);

            // حذف الوحدات التي لم تعد موجودة
            $unitsToDelete = array_diff($existingUnitIds, $newUnitIds);
            if (!empty($unitsToDelete)) {
                $product->units()->whereIn('unit_id', $unitsToDelete)->each(function ($unit) {
                    $unit->colors()->delete();
                    $unit->delete();
                });
            }

            // تحديث أو إضافة الوحدات الجديدة
            foreach ($organizedUnits as $unitId => $unitData) {
                // ✅ استخدام updateOrCreate بدلاً من create
                $productUnit = $product->units()->updateOrCreate(
                    ['unit_id' => $unitId],
                    [
                        'cost_price' => $unitData['cost_price'],
                        'sell_price' => $unitData['sell_price'],
                        'barcode' => $unitData['barcode'] ?? null,
                    ]
                );

                // حذف الألوان القديمة لهذه الوحدة
                $productUnit->colors()->delete();

                // إضافة الألوان الجديدة
                if (!empty($unitData['colors'])) {
                    foreach ($unitData['colors'] as $colorData) {
                        $productUnit->colors()->create([
                            'color_id' => $colorData['color_id'],
                            'stock'    => $colorData['stock'],
                        ]);
                    }
                }
            }
        } else {
            // إذا ما فيش units، احذف كل الحجات القديمة
            $product->units()->each(function ($unit) {
                $unit->colors()->delete();
                $unit->delete();
            });
        }

        DB::commit();

        return JsonResponse::respondSuccess(
            trans(JsonResponse::MSG_UPDATED_SUCCESSFULLY),
            new ProductResource($product->load('units.colors'))
        );

    } catch (\Exception $e) {
        DB::rollBack();
        return JsonResponse::respondError($e->getMessage());
    }
}


    public function destroy(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecords('products', $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function restore(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->restoreItem(Product::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_RESTORED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }




    public function forceDelete(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecordsFinial(Product::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_FORCE_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function searchByProductName(Request $request)
    {
        $request->validate([
            'name' => 'required|string'
        ]);

        try {
            $products = Product::with(['category'])
                ->where('name', 'LIKE', '%' . $request->query('name') . '%')
                ->get();

            if ($products->isEmpty()) {
                return response()->json([
                    'status'  => false,
                    'message' => "لا توجد منتجات باسم {$request->query('name')}"
                ], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'تم العثور على المنتجات',
                'data'    => $products
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء البحث',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

  public function getProductsByBranch(Request $request)
{
    $data = $request->validate([
        'branch_id' => ['required', 'exists:branches,id'],
        'category_id' => ['nullable', 'exists:categories,id'],
    ]);

    $branchId = (int) $data['branch_id'];

    $products = Product::query()
        ->with([
            'category',
            'units.colors',
        ])

        // هات المنتجات المسجلة في مخازن هذا الفرع فقط
        ->whereHas('warehouses', function ($query) use ($branchId) {
            $query->where('branch_id', $branchId);
        })

        // فلتر التصنيف لو موجود
        ->when(
            !empty($data['category_id']),
            function ($query) use ($data) {
                $query->where('category_id', $data['category_id']);
            }
        )

        ->distinct()
        ->get();

    return ProductResource::collection($products);
}

    public function warehouseStock(Request $request)
    {
        $data = $request->validate([
            'filters.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'filters.product_id_in' => ['nullable', 'array'],
            'filters.product_id_in.*' => ['integer'],
        ]);

        $filters = $data['filters'];
        $query = ProductWarehouse::query()
            ->where('warehouse_id', (int) $filters['warehouse_id']);

        if (!empty($filters['product_id_in'])) {
            $query->whereIn('product_id', $filters['product_id_in']);
        }

        return JsonResponse::respondSuccess('Success', $query->get(['product_id', 'warehouse_id', 'stock']));
    }



  public function getRevenueReport(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $branch_id = $data['branch_id'] ?? null;

        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();
        $threeMonthsAgo = Carbon::now()->subMonths(3)->startOfMonth();

        // =========================
        // Query الفواتير
        // =========================
        $itemsQuery = InvoiceItem::with([
            'invoice.branch',
            'product.category'
        ]);

        if ($branch_id) {
            $itemsQuery->whereHas('invoice', function ($q) use ($branch_id) {
                $q->where('branch_id', $branch_id);
            });
        }

        $items = $itemsQuery->get();

        // =========================
        // الإيرادات حسب الفترة
        // =========================
        $todayRevenue = $items->filter(function ($item) use ($today) {
            return $item->invoice &&
                $item->invoice->created_at >= $today;
        })->sum('total');

        $monthRevenue = $items->filter(function ($item) use ($startOfMonth) {
            return $item->invoice &&
                $item->invoice->created_at >= $startOfMonth;
        })->sum('total');

        $threeMonthsRevenue = $items->filter(function ($item) use ($threeMonthsAgo) {
            return $item->invoice &&
                $item->invoice->created_at >= $threeMonthsAgo;
        })->sum('total');

        // =========================
        // كل التصنيفات + الكميات
        // =========================
        $categorySales = $items
            ->filter(fn($item) => $item->product)
            ->groupBy(function ($item) {
                return optional($item->product)->category_id;
            })
            ->map(function ($group) {
                return $group->sum('quantity');
            });

        $topCategories = Category::query()
            ->select('id', 'name')
            ->get()
            ->map(function ($category) use ($categorySales) {
                return [
                    'category_id' => $category->id,
                    'category_name' => $category->name, // اسم التصنيف
                    'total_quantity' => $categorySales[$category->id] ?? 0
                ];
            })
            ->sortByDesc('total_quantity')
            ->whereNull('parent_id')
            ->take(5)
            ->values();

        // =========================
        // الإيرادات لكل فرع
        // =========================
        $branchRevenues = $items
            ->filter(fn($item) => $item->invoice)
            ->groupBy(function ($item) {
                return $item->invoice->branch_id;
            })
            ->map(function ($group) {
                $branch = optional($group->first()->invoice->branch);

                return [
                    'branch_id' => $branch->id ?? null,
                    'branch_name' => $branch->name ?? 'N/A',
                    'revenue' => $group->sum('total')
                ];
            })
            ->values();

        return response()->json([
            'today_revenue' => $todayRevenue,
            'month_revenue' => $monthRevenue,
            'three_months_revenue' => $threeMonthsRevenue,
            'top_categories' => $topCategories,
            'branch_revenues' => $branchRevenues
        ]);
    }

    public function importProducts(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv'
        ]);

        try {

            Excel::import(new ProductImport, $request->file('file'));

            return response()->json([
                'status' => true,
                'message' => 'The products were imported successfully.'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'An error occurred during import',
                'error' => $e->getMessage()
            ], 500);
        }
    }




    public function addStock(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.warehouse_id' => 'required|exists:warehouses,id',
            'items.*.unit_id'      => 'nullable|exists:units,id',
            'items.*.color_id'     => 'nullable|exists:colors,id',
            'items.*.stock'        => 'required|numeric|min:1',
            'items.*.cost'         => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {

        foreach ($request->items as $item) {

            // 1️⃣ المنتج
            $product = Product::findOrFail($item['product_id']);
            $product->increment('stock', $item['stock']);
            $product->cost = $item['cost'];
            $product->beginning_balance = 1;
            $product->save();

            // 2️⃣ warehouse
            $productWarehouse = ProductWarehouse::firstOrCreate([
                'product_id'   => $item['product_id'],
                'warehouse_id' => $item['warehouse_id'],
            ]);

            $productWarehouse->increment('stock', $item['stock']);

            // ✅ استخدم data_get عشان لو مش موجود مايعملش error
            $unitId  = data_get($item, 'unit_id');
            $colorId = data_get($item, 'color_id');

            $productUnit = null;

            // 3️⃣ unit (اختياري)
            if ($unitId) {
                $productUnit = ProductUnit::firstOrCreate([
                    'product_id' => $item['product_id'],
                    'unit_id'    => $unitId,
                ]);
            }

            // 4️⃣ color (اختياري برضه)
            if ($productUnit && $colorId) {
                $unitColor = ProductUnitColor::firstOrCreate([
                    'product_unit_id' => $productUnit->id,
                    'color_id'        => $colorId,
                ]);

                $unitColor->increment('stock', $item['stock']);
            }
        }

            DB::commit();

            return response()->json([
                'message' => 'Stock added successfully'
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function updateStock(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'unit_id' => 'nullable|exists:units,id',
            'color_id' => 'nullable|exists:colors,id',
            'stock' => 'required|numeric|min:0',
            'cost' => 'required|numeric|min:0',
            'old_warehouse_id' => 'nullable|exists:warehouses,id',
            'old_unit_id' => 'nullable|exists:units,id',
            'old_color_id' => 'nullable|exists:colors,id',
        ]);

        try {
            $result = DB::transaction(function () use ($request) {
                $productId = (int) $request->product_id;
                $newStock = (float) $request->stock;
                $targetWarehouseId = (int) $request->warehouse_id;
                $oldWarehouseId = (int) ($request->old_warehouse_id ?: $targetWarehouseId);
                $product = Product::query()->lockForUpdate()->findOrFail($productId);

                // This update must find legacy rows even when their tenant_id
                // was added after the row was created. The authenticated
                // tenant is already resolved by the route middleware.
                $oldWarehouse = ProductWarehouse::withoutGlobalScopes()
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $oldWarehouseId)
                    ->lockForUpdate()
                    ->first();
                $targetWarehouse = ProductWarehouse::withoutGlobalScopes()
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $targetWarehouseId)
                    ->lockForUpdate()
                    ->first();

                // The entered number is the final quantity, never an increment.
                if ($oldWarehouseId !== $targetWarehouseId && $oldWarehouse) {
                    $oldWarehouse->update(['stock' => 0]);
                }
                $targetWarehouse ??= ProductWarehouse::withoutGlobalScopes()->create([
                    'product_id' => $productId,
                    'warehouse_id' => $targetWarehouseId,
                    'stock' => 0,
                    'cost' => $request->cost,
                ]);
                $targetWarehouse->update([
                    'stock' => $newStock,
                    'cost' => $request->cost,
                ]);

                if ($request->filled('old_unit_id') && $request->filled('old_color_id')) {
                    $oldUnit = ProductUnit::where('product_id', $productId)
                        ->where('unit_id', $request->old_unit_id)->first();
                    if ($oldUnit) {
                        ProductUnitColor::where('product_unit_id', $oldUnit->id)
                            ->where('color_id', $request->old_color_id)
                            ->update(['stock' => 0]);
                    }
                }
                if ($request->filled('unit_id') && $request->filled('color_id')) {
                    $unit = ProductUnit::firstOrCreate([
                        'product_id' => $productId,
                        'unit_id' => $request->unit_id,
                    ]);
                    ProductUnitColor::updateOrCreate(
                        ['product_unit_id' => $unit->id, 'color_id' => $request->color_id],
                        ['stock' => $newStock]
                    );
                }

                $product->update([
                    'stock' => $newStock,
                    'cost' => $request->cost,
                    'beginning_balance' => true,
                ]);

                return ['stock' => $newStock, 'warehouse_id' => $targetWarehouseId];
            });

            return response()->json([
                'result' => 'Success',
                'message' => 'Opening balance updated to the exact quantity.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json(['result' => 'Error', 'message' => $e->getMessage()], 422);
        }
    }

    public function deleteOpeningBalance(Product $product): \Illuminate\Http\JsonResponse
    {
        try {
            DB::transaction(function () use ($product): void {
                $product->update([
                    'stock' => 0,
                    'beginning_balance' => false,
                ]);

                ProductWarehouse::withoutGlobalScopes()
                    ->where('product_id', $product->id)
                    ->update(['stock' => 0]);

                ProductUnitColor::whereHas('productUnit', function ($query) use ($product): void {
                    $query->where('product_id', $product->id);
                })->update(['stock' => 0]);
            });

            return response()->json([
                'result' => 'Success',
                'message' => 'Opening balance deleted successfully.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

public function stockDetails(Request $request)
{
    $request->validate([
        'product_id'   => 'required|exists:products,id',
        'warehouse_id' => 'nullable|exists:warehouses,id',
    ]);

    try {
        $productId = (int) $request->product_id;
        $warehouseId = $request->filled('warehouse_id')
            ? (int) $request->warehouse_id
            : null;

        $product = Product::findOrFail($productId);

        // ✅ الـ ProductWarehouse
        if ($warehouseId) {
            $productWarehouse = ProductWarehouse::with(['warehouse.branch'])
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->first();
        } else {
            $productWarehouse = ProductWarehouse::with(['warehouse.branch'])
                ->where('product_id', $productId)
                ->where('stock', '>', 0)
                ->first();

            if (!$productWarehouse) {
                $productWarehouse = ProductWarehouse::with(['warehouse.branch'])
                    ->where('product_id', $productId)
                    ->first();
            }
        }

        // ✅ الـ ProductUnit + Color
        $productUnit = ProductUnit::where('product_id', $productId)->first();
        $productUnitColor = $productUnit
            ? ProductUnitColor::where('product_unit_id', $productUnit->id)->first()
            : null;

        // ✅ احسب الكميات
        $productStock = (float) $product->stock;
        $warehouseStock = (float) ($productWarehouse?->stock ?? 0);
        $unitColorStock = (float) ($productUnitColor?->stock ?? 0);

        // ✅ الكمية الصح: 
        // 1. لو فيه unit_color_stock > 0 → استخدمها
        // 2. لو مفيش → استخدم warehouse_stock
        // 3. لو مفيش → استخدم product_stock
        $displayStock = 0;
        if ($unitColorStock > 0) {
            $displayStock = $unitColorStock;
        } elseif ($warehouseStock > 0) {
            $displayStock = $warehouseStock;
        } else {
            $displayStock = $productStock;
        }

        // ✅ Fallback لو مفيش warehouse
        if (!$productWarehouse) {
            $fallbackWarehouse = \App\Models\Warehouse::where('tenant_id', $product->tenant_id)
                ->whereNull('deleted_at')
                ->orderByDesc('main_branch')
                ->orderBy('id')
                ->first();

            return response()->json([
                'result' => 'Success',
                'message' => 'Stock details fetched (no warehouse record yet)',
                'data' => [
                    'record_id'         => null,
                    'product_id'        => $product->id,
                    'warehouse_id'      => $fallbackWarehouse?->id,
                    'branch_id'         => $fallbackWarehouse?->branch_id,
                    'branch_name'       => $fallbackWarehouse?->branch?->name,
                    'branch_name_ar'    => $fallbackWarehouse?->branch?->name_ar,
                    'warehouse_name'    => $fallbackWarehouse?->name,
                    'warehouse_name_ar' => $fallbackWarehouse?->name_ar,
                    'unit_id'           => $productUnit?->unit_id,
                    'color_id'          => $productUnitColor?->color_id,

                    'product_stock'     => $productStock,
                    'warehouse_stock'   => 0,
                    'unit_color_stock'  => $unitColorStock,
                    'stock'             => $displayStock,

                    'cost'              => $product->cost,
                    'price'             => $product->price,
                    'warehouse'         => $fallbackWarehouse,
                ],
            ]);
        }

        return response()->json([
            'result' => 'Success',
            'message' => 'Stock details fetched successfully',
            'data' => [
                'record_id'         => $productWarehouse->id,
                'product_id'        => $productWarehouse->product_id,
                'warehouse_id'      => $productWarehouse->warehouse_id,
                'branch_id'         => $productWarehouse->warehouse?->branch_id,
                'branch_name'       => $productWarehouse->warehouse?->branch?->name,
                'branch_name_ar'    => $productWarehouse->warehouse?->branch?->name_ar,
                'warehouse_name'    => $productWarehouse->warehouse?->name,
                'warehouse_name_ar' => $productWarehouse->warehouse?->name_ar,
                'unit_id'           => $productUnit?->unit_id,
                'color_id'          => $productUnitColor?->color_id,

                'product_stock'     => $productStock,
                'warehouse_stock'   => $warehouseStock,
                'unit_color_stock'  => $unitColorStock,
                'stock'             => $displayStock,  // ✅ الكمية الصح

                'cost'              => $productWarehouse->cost ?? $product->cost,
                'price'             => $product->price,
                'warehouse'         => $productWarehouse->warehouse,
            ],
        ]);
    } catch (Exception $e) {
        return response()->json([
            'result' => 'Error',
            'message' => $e->getMessage(),
        ], 500);
    }
}

public function productWarehouses(Request $request)
{
    $request->validate([
        'product_id' => 'required|exists:products,id',
    ]);

    try {
        $productId = (int) $request->product_id;
        $product = Product::findOrFail($productId);

        // ✅ كل مخازن الـ tenant مع الـ branch
        $tenantWarehouses = \App\Models\Warehouse::where('tenant_id', $product->tenant_id)
            ->whereNull('deleted_at')
            ->with('branch')
            ->orderByDesc('main_branch')
            ->orderBy('id')
            ->get();

        // ✅ الـ ProductWarehouse مرتبطة بالمنتج
        $productWarehouseMap = ProductWarehouse::where('product_id', $productId)
            ->get()
            ->keyBy('warehouse_id');

        $data = $tenantWarehouses->map(function ($w) use ($productWarehouseMap) {
            $pw = $productWarehouseMap->get($w->id);

            return [
                'record_id'         => $pw?->id,
                'warehouse_id'      => $w->id,
                'warehouse_name'    => $w->name,
                'warehouse_name_ar' => $w->name_ar,
                'branch_id'         => $w->branch_id,              // ✅
                'branch_name'       => $w->branch?->name,          // ✅
                'branch_name_ar'    => $w->branch?->name_ar,       // ✅
                'stock'             => (float) ($pw?->stock ?? 0),
                'cost'              => (float) ($pw?->cost ?? 0),
                'has_stock'         => $pw !== null && (float) $pw->stock > 0,
            ];
        });

        return response()->json([
            'result' => 'Success',
            'data' => $data,
        ]);
    } catch (Exception $e) {
        return response()->json([
            'result' => 'Error',
            'message' => $e->getMessage(),
        ], 500);
    }
}
private function linkWarehouses(Product $product, ?array $warehouseIds): void
{
    $tenantId = $product->tenant_id;

    if (empty($warehouseIds)) {
        // لو المنتج مربوط بمخزن بالفعل، متعملش حاجة
        if (ProductWarehouse::where('product_id', $product->id)->exists()) {
            return;
        }

        // ✅ اختار المخزن الرئيسي لنفس الـ tenant فقط
        $main = \App\Models\Warehouse::where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderByDesc('main_branch')
            ->orderBy('id')
            ->first();

        $warehouseIds = $main ? [$main->id] : [];
    }

    if (empty($warehouseIds)) {
        \Log::warning("No warehouse found for product {$product->id} (tenant {$tenantId})");
        return;
    }

    foreach ($warehouseIds as $warehouseId) {
        ProductWarehouse::firstOrCreate(
            [
                'product_id'   => $product->id,
                'warehouse_id' => (int) $warehouseId,
            ],
            [
                'stock'     => 0,   // ✅ متحطش stock وهمي
                'cost'      => $product->cost ?? 0,
                'tenant_id' => $tenantId,
            ]
        );
    }
}

}
