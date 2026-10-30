<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\ProductRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Imports\ProductImport;
use App\Interfaces\ProductRepositoryInterface;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Product;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\InvoiceItem;
use App\Models\ProductUnit;
use App\Models\ProductUnitColor;
use App\Models\ProductWarehouse;
use App\Models\ProductBranchPrice;

class ProductController extends BaseController
{

    protected mixed $crudRepository;

    public function __construct(ProductRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }


/**
 * المنتجات القابلة للشراء فقط (بدون الخدمات).
 * تُستخدم في شاشات المشتريات.
 */
public function purchasable(Request $request)
{
    try {
        $query = Product::query()
            ->purchasable() // ✅ استبعاد الخدمات
            ->with(['units', 'media', 'category']);

        // البحث جاي من filters.name (زي باقي الـ endpoints)
        $filters = $request->input('filters', []);
        if (!empty($filters['name'])) {
            $name = trim((string) $filters['name']);
            $hasArabicNameColumn = Schema::hasColumn('products', 'name_ar');
            $query->where(function ($q) use ($name, $hasArabicNameColumn) {
                $q->where('name', 'LIKE', '%' . $name . '%');
                if ($hasArabicNameColumn) {
                    $q->orWhere('name_ar', 'LIKE', '%' . $name . '%');
                }
            });
        }

        if (!empty($filters['sku'])) {
            $query->where('sku', 'LIKE', '%' . trim((string) $filters['sku']) . '%');
        }

        if (!empty($filters['barcode'])) {
            $barcode = trim((string) $filters['barcode']);
            $query->where(function ($q) use ($barcode) {
                $q->where('barcode', 'LIKE', '%' . $barcode . '%')
                  ->orWhereHas('units', function ($unitQuery) use ($barcode) {
                      $unitQuery->where('barcode', 'LIKE', '%' . $barcode . '%');
                  });
            });
        }

        if (array_key_exists('active', $filters)) {
            $query->where('active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        } else {
            $query->where('active', true);
        }

        // فلترة بالفرع
        if ($branchId = $request->input('filters.branch_id')) {
            $query->where('branch_id', (int) $branchId);
        }

        $orderBy = $request->input('orderBy', 'name');
        $direction = strtolower($request->input('orderByDirection', 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($orderBy, $direction);

        // pagination
        if ($request->boolean('paginate', false)) {
            $perPage = (int) $request->input('perPage', 50);
            $products = $query->paginate($perPage);

            return response()->json([
                'data'   => \App\Http\Resources\ProductResource::collection($products->items()),
                'meta'   => [
                    'current_page' => $products->currentPage(),
                    'last_page'    => $products->lastPage(),
                    'per_page'     => $products->perPage(),
                    'total'        => $products->total(),
                ],
                'result'  => 'Success',
                'message' => 'Purchasable products fetched successfully',
                'status'  => 200,
            ]);
        }

        $products = $query->get();

        return response()->json([
            'data'    => \App\Http\Resources\ProductResource::collection($products),
            'result'  => 'Success',
            'message' => 'Purchasable products fetched successfully',
            'status'  => 200,
        ]);

    } catch (\Throwable $e) {
        return response()->json([
            'result'  => 'Error',
            'message' => $e->getMessage(),
            'status'  => 500,
        ], 500);
    }
}


public function index(Request $request)
{
    try {
        $filters = $request->input('filters', []);

        $query = Product::query()
            ->withoutGlobalScope('branch')
            ->with(['category', 'units.colors', 'warehouses', 'automotiveService', 'branchPrices']);

        if (!empty($filters['name'])) {
            $name = trim((string) $filters['name']);
            $hasArabicNameColumn = Schema::hasColumn('products', 'name_ar');
            $query->where(function ($q) use ($name, $hasArabicNameColumn) {
                $q->where('name', 'LIKE', '%' . $name . '%');
                if ($hasArabicNameColumn) {
                    $q->orWhere('name_ar', 'LIKE', '%' . $name . '%');
                }
            });
        }

        if (!empty($filters['sku'])) {
            $query->where('sku', 'LIKE', '%' . trim((string) $filters['sku']) . '%');
        }

        if (!empty($filters['barcode'])) {
            $barcode = trim((string) $filters['barcode']);
            $query->where(function ($q) use ($barcode) {
                $q->where('barcode', 'LIKE', '%' . $barcode . '%')
                    ->orWhereHas('units', function ($unitQuery) use ($barcode) {
                        $unitQuery->where('barcode', 'LIKE', '%' . $barcode . '%');
                    });
            });
        }

        // فلتر المخزن: من جدول product_warehouse مباشرة بدون أي Scope للفرع
        $warehouseFilterId = (int) ($filters['stock_warehouse_id'] ?? $filters['warehouse_id'] ?? 0);
        if ($warehouseFilterId) {
            $query->whereIn(
                'products.id',
                ProductWarehouse::withoutGlobalScopes()
                    ->where('warehouse_id', $warehouseFilterId)
                    ->select('product_id')
            );
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

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

        $priceBranchId = !empty($filters['branch_id'])
            ? (int) $filters['branch_id']
            : ((int) ($request->user()?->branch_id ?: (app()->bound('currentBranchId') ? app('currentBranchId') : 0)) ?: null);

        if ($priceBranchId) {
            $products->each(function (Product $product) use ($priceBranchId): void {
                $override = $product->branchPrices->firstWhere('branch_id', $priceBranchId);
                $product->setAttribute('display_price', (float) ($override?->price ?? $product->price ?? 0));
            });
        }

        // كميات كل المخازن لكل المنتجات (استعلام واحد)
        $stocksByProduct = ProductWarehouse::withoutGlobalScopes()
            ->whereIn('product_id', $products->pluck('id'))
            ->get(['product_id', 'warehouse_id', 'stock'])
            ->groupBy('product_id');

        $data = $products->map(function (Product $product) use ($stocksByProduct, $warehouseFilterId) {
            $map = ($stocksByProduct[$product->id] ?? collect())
                ->mapWithKeys(fn ($pw) => [(int) $pw->warehouse_id => (float) $pw->stock])
                ->all();

            if ($warehouseFilterId) {
                $product->setAttribute('display_stock', (float) ($map[$warehouseFilterId] ?? 0));
            }
$row = (new ProductResource($product))->resolve();
return $this->withComputedStock($row, $map);
        })->values();

        return response()->json(array_merge(['data' => $data], JsonResponse::success()));
    } catch (Exception $e) {
        return JsonResponse::respondError($e->getMessage());
    }
}
private function withComputedStock(array $row, array $map): array
{
    $row['warehouse_stocks'] = (object) $map;
    $row['warehouse_ids']    = array_map('intval', array_keys($map));
    // لو للمنتج صفوف مخازن: المجموع. لو مفيش: نترك القيمة المسجلة كما هي.
    if (!empty($map)) {
        $row['stock'] = (float) array_sum($map);
    }
    return $row;
}
public function lowStockAlerts(Request $request)
{
    try {
        $products = Product::query()
            ->whereColumn('stock', '<=', DB::raw('CASE WHEN reorder_level > 0 THEN reorder_level ELSE 5 END'))
            ->orderBy('stock')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock', 'reorder_level', 'updated_at']);

        $data = $products->map(function (Product $product): array {
            $threshold = (int) ($product->reorder_level > 0 ? $product->reorder_level : 5);
            return [
                'id' => $product->id,
                'product' => ['id' => $product->id, 'name' => $product->name, 'name_ar' => $product->name_ar, 'sku' => $product->sku],
                'current_quantity' => (int) ($product->stock ?? 0),
                'threshold_quantity' => $threshold,
                'alert_type' => (int) ($product->stock ?? 0) <= 0 ? 'out_of_stock' : 'low_stock',
                'created_at' => $product->updated_at,
            ];
        })->values();

        return response()->json(['status' => true, 'data' => $data]);
    } catch (Exception $e) {
        return JsonResponse::respondError($e->getMessage());
    }
}

public function show(Product $product)
{
    try {
        // ✅ بدون deleted_at على product_warehouse
        $stocks = DB::table('product_warehouse')
            ->where('product_id', $product->id)
            ->get(['warehouse_id', 'stock'])
            ->groupBy('warehouse_id')
            ->map(fn ($group) => (float) $group->sum('stock'))
            ->all();

        $product->unsetRelation('warehouses');
        $product->load(['units.colors', 'branchPrices', 'warehouses']);

        $row = (new ProductResource($product))->resolve();

        $row['warehouse_stocks'] = (object) $stocks;
        $row['warehouse_ids']    = array_map('intval', array_keys($stocks));
        $row['stock']            = (float) array_sum($stocks);

        return response()->json([
            'result'  => 'Success',
            'message' => 'Item Fetched Successfully',
            'data'    => $row,
            'status'  => 200,
        ]);
    } catch (Exception $e) {
        return JsonResponse::respondError($e->getMessage());
    }
}

public function store(ProductRequest $request)
{
    DB::beginTransaction();

    try {
        $data = $request->validated();

      // ✅ استثنِ الحقول اللي بتتعامل يدوياً
$createData = collect($data)->except([
    'units', 'warehouse_ids', 'warehouse_stocks', 'branch_ids', 'stock',
    'image', 'image_url'
])->toArray();

// ✅ فلترة: احتفظ بس بالأعمدة الموجودة فعلاً في جدول products
$createData = collect($createData)
    ->filter(fn ($v, $k) => \Illuminate\Support\Facades\Schema::hasColumn('products', $k))
    ->toArray();

$product = $this->crudRepository->create($createData);

        $this->syncBranchPrice($product, $request, $data['price'] ?? null);

        // الوحدات والألوان
        $this->syncProductUnits($product, $data['units'] ?? [], false);

        // ✅ المخازن — خد من $data (validated) مش من $request
        $warehouseIds = array_key_exists('warehouse_ids', $data)
            ? array_map('intval', (array) $data['warehouse_ids'])
            : null;

        $warehouseStocks = $this->normalizeWarehouseStocksInput(
            $data['warehouse_stocks'] ?? []
        );

        $this->linkWarehouses(
            $product,
            $warehouseIds,
            $warehouseStocks,
            false
        );

        if (request('image') !== null) {
            $this->crudRepository->AddMediaCollection('image', $product);
        }

        DB::commit();

        return new ProductResource($product->load(['units.colors', 'branchPrices']));
    } catch (Exception $e) {
        DB::rollBack();
        return JsonResponse::respondError($e->getMessage());
    }
}


    


public function update(ProductUpdateRequest $request, Product $product)
{
    try {
        $result = DB::transaction(function () use ($request, $product) {

            $data = $request->validated();

            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->id);

            \Log::info('PRODUCT UPDATE HIT', [
                'product_id' => $product->id,
                'tenant_id' => $product->tenant_id,
                'method' => $request->method(),
                'warehouse_ids' => $data['warehouse_ids'] ?? null,
                'warehouse_stocks' => $data['warehouse_stocks'] ?? null,
            ]);

            // 1. تحديث بيانات المنتج الأساسية فقط
            $updateData = collect($data)->except([
                'units',
                'image',
                'image_url',
                'warehouse_ids',
                'warehouse_stocks',
                'sync_warehouses',
                'branch_ids',
                'price',
                'stock',
            ])->toArray();

            $updateData = collect($updateData)
                ->filter(fn ($value, $key) =>
                    Schema::hasColumn('products', $key)
                )
                ->toArray();

            if (!empty($updateData)) {
                $this->crudRepository->update(
                    $updateData,
                    $product->id
                );
            }

            $product->refresh();

            // 2. تحديث سعر الفرع فقط لو price اتبعت
            if (array_key_exists('price', $data)) {
                $this->syncBranchPrice(
                    $product,
                    $request,
                    $data['price']
                );
            }

            // 3. تجهيز كميات المخازن
            $warehouseStocks = $this->normalizeWarehouseStocksInput(
                $data['warehouse_stocks'] ?? []
            );

            // لو warehouse_ids اتبعتت، نستخدمها.
            // لو لم تُرسل، نحدث المخازن الموجودة فقط،
            // ونضيف أي مخزن موجود في مفاتيح warehouse_stocks.
            if (array_key_exists('warehouse_ids', $data)) {
                $warehouseIds = array_map(
                    'intval',
                    $data['warehouse_ids']
                );
            } else {
                $warehouseIds = DB::table('product_warehouse')
                    ->where('product_id', $product->id)
                    ->pluck('warehouse_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $warehouseIds = array_merge(
                    $warehouseIds,
                    array_map('intval', array_keys($warehouseStocks))
                );
            }

            $warehouseIds = array_values(array_unique($warehouseIds));

            // الحذف لا يحدث إلا بطلب صريح
            $shouldSyncWarehouses = (bool) (
                $data['sync_warehouses'] ?? false
            );

            \Log::info('WAREHOUSE DATA BEFORE UPDATE', [
                'product_id' => $product->id,
                'warehouse_ids' => $warehouseIds,
                'warehouse_stocks' => $warehouseStocks,
                'sync_warehouses' => $shouldSyncWarehouses,
            ]);


            $removedIds = array_values(array_diff(
    array_map('intval', $data['removed_warehouse_ids'] ?? []),
    $warehouseIds
));

if ($removedIds) {
    DB::table('product_warehouse')
        ->where('product_id', $product->id)
        ->whereIn('warehouse_id', $removedIds)
        ->delete();
}
            // 4. تحديث المخازن
            if (
                array_key_exists('warehouse_ids', $data) ||
                array_key_exists('warehouse_stocks', $data)
            ) {
                $this->linkWarehouses(
                    $product,
                    $warehouseIds,
                    $warehouseStocks,
                    $shouldSyncWarehouses
                );
            }

            // 5. تحديث الوحدات فقط لو units اتبعتت
            // مهم: عدم إرسال units لا يعني حذف الوحدات.
            if (array_key_exists('units', $data)) {
                $this->syncProductUnits(
                    $product,
                    $data['units'] ?? [],
                    true
                );
            }

            // 6. الصورة
            if (
                $request->has('image') &&
                $request->input('image') !== null
            ) {
                $this->crudRepository->AddMediaCollection(
                    'image',
                    $product
                );
            }

            // 7. قراءة الكميات النهائية من قاعدة البيانات
            $finalRows = DB::table('product_warehouse')
                ->where('product_id', $product->id)
                ->orderBy('warehouse_id')
                ->get(['warehouse_id', 'stock']);

            $finalStocks = $finalRows
                ->groupBy('warehouse_id')
                ->map(fn ($rows) => (float) $rows->sum('stock'))
                ->all();

            $totalStock = (float) array_sum($finalStocks);

            \Log::info('FINAL WAREHOUSE CHECK', [
                'product_id' => $product->id,
                'sent' => $warehouseStocks,
                'database' => $finalStocks,
                'total_stock' => $totalStock,
            ]);

            $product->refresh();

            $row = (new ProductResource(
                $product->load([
                    'units.colors',
                    'branchPrices',
                    'warehouses',
                ])
            ))->resolve();

            // إرجاع كميات المخازن في نفس استجابة التحديث
            $row['warehouse_stocks'] = (object) $finalStocks;
            $row['warehouse_ids'] = array_map(
                'intval',
                array_keys($finalStocks)
            );
            $row['stock'] = $totalStock;

            return $row;
        });

        return JsonResponse::respondSuccess(
            trans(JsonResponse::MSG_UPDATED_SUCCESSFULLY),
            $result
        );

    } catch (\Throwable $e) {

        \Log::error('PRODUCT UPDATE FAILED', [
            'product_id' => $product->id,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

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

private function syncProductUnits(Product $product, array $units, bool $isUpdate = false): void
{
    if (empty($units) || !is_array($units)) {
        if ($isUpdate) {
            $product->units()->each(function ($unit) {
                $unit->colors()->delete();
                $unit->delete();
            });
        }
        return;
    }

    // تجميع الوحدات
    $organizedUnits = [];
    foreach ($units as $unitData) {
        $unitId = $unitData['unit_id'];
        if (!isset($organizedUnits[$unitId])) {
            $organizedUnits[$unitId] = [
                'unit_id'    => $unitId,
                'cost_price' => $unitData['cost_price'] ?? 0,
                'sell_price' => $unitData['sell_price'] ?? 0,
                'barcode'    => $unitData['barcode'] ?? null,
                'colors'     => [],
            ];
        }
        if (!empty($unitData['colors'])) {
            foreach ($unitData['colors'] as $colorData) {
                $exists = false;
                foreach ($organizedUnits[$unitId]['colors'] as $existing) {
                    if ($existing['color_id'] == $colorData['color_id']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $organizedUnits[$unitId]['colors'][] = $colorData;
                }
            }
        }
    }

    if ($isUpdate) {
        $existingUnitIds = $product->units()->pluck('unit_id')->toArray();
        $newUnitIds      = array_keys($organizedUnits);
        $unitsToDelete   = array_diff($existingUnitIds, $newUnitIds);

        if (!empty($unitsToDelete)) {
            $product->units()->whereIn('unit_id', $unitsToDelete)->each(function ($unit) {
                $unit->colors()->delete();
                $unit->delete();
            });
        }
    }

    foreach ($organizedUnits as $unitId => $unitData) {
        $productUnit = $isUpdate
            ? $product->units()->updateOrCreate(
                ['unit_id' => $unitId],
                [
                    'cost_price' => $unitData['cost_price'],
                    'sell_price' => $unitData['sell_price'],
                    'barcode'    => $unitData['barcode'] ?? null,
                ]
            )
            : $product->units()->create([
                'unit_id'    => $unitId,
                'cost_price' => $unitData['cost_price'],
                'sell_price' => $unitData['sell_price'],
                'barcode'    => $unitData['barcode'] ?? null,
            ]);

        if ($isUpdate) {
            $productUnit->colors()->delete();
        }

        foreach ($unitData['colors'] as $colorData) {
            $productUnit->colors()->create([
                'color_id' => $colorData['color_id'],
                'stock'    => $colorData['stock'] ?? 0,
            ]);
        }
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
          'branch_id' => ['required', 'integer'],
        'category_id' => ['nullable', 'exists:categories,id'],
    ]);

    $branchId = (int) $data['branch_id'];
      $user = $request->user();
      $tenantId = $user?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);

      abort_unless($user && $tenantId, 403, 'A tenant context is required.');
      if ($user instanceof Employee) {
          abort_unless((int) $user->branch_id > 0 && (int) $user->branch_id === $branchId, 403, 'You may only view your assigned branch catalog.');
      } elseif (!($user->super_admin ?? false) && $user->branch_id) {
          abort_unless((int) $user->branch_id === $branchId, 403, 'You may only view your assigned branch catalog.');
      }

      // Warehouses are branch-scoped by BaseModel. POS may only access its own
      // branch, but admins can select a branch explicitly; tenant ownership is
      // always applied explicitly when bypassing the branch scope.
      $branchQuery = DB::table('branches')
          ->join('warehouses', 'warehouses.branch_id', '=', 'branches.id')
          ->where('branches.id', $branchId)
          ->where('branches.active', true)
          ->whereNull('branches.deleted_at')
          ->where('warehouses.tenant_id', $tenantId)
          ->where('warehouses.branch_id', $branchId);
      $branchQuery->where('warehouses.active', true)->whereNull('warehouses.deleted_at');
      if (Schema::hasColumn('branches', 'tenant_id')) {
          $branchQuery->where('branches.tenant_id', $tenantId);
      }
      $branchIsAvailable = $branchQuery->exists();
      abort_unless($branchIsAvailable, 404, 'Branch not found.');

        $products = Product::query()
          ->withoutGlobalScope('branch')
          ->where('products.tenant_id', $tenantId)
          ->with([
              'category',
              'automotiveService',
              'branchPrices' => function ($query) use ($branchId) {
                  $query->where('branch_id', $branchId);
              },
              'units.colors',
              'warehouses' => function ($query) use ($branchId, $tenantId) {
                  $query->withoutGlobalScope('branch')
                      ->where('warehouses.tenant_id', $tenantId)
                      ->where('warehouses.branch_id', $branchId)
                      ->withPivot(['stock']);
              },
          ])

        // Regular products must be registered in this branch's warehouse.
        // Automotive services are tenant catalog entries and may not have a
        // product_warehouse row, so they must remain visible in POS as well.
        ->where(function ($query) use ($branchId, $tenantId) {
            $query->whereHas('warehouses', function ($warehouseQuery) use ($branchId, $tenantId) {
                $warehouseQuery->withoutGlobalScope('branch')
                    ->where('warehouses.tenant_id', $tenantId)
                    ->where('warehouses.branch_id', $branchId);
            })->orWhereHas('automotiveService', function ($serviceQuery) {
                $serviceQuery->where('active', true);
            });
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

    // The POS must use stock for the selected branch, not the tenant-wide
    // product aggregate, so a pending transfer cannot make stock sellable.
    $products->each(function (Product $product) use ($branchId): void {
        $override = $product->branchPrices->firstWhere('branch_id', $branchId);
        $product->setAttribute('display_price', (float) ($override?->price ?? $product->price ?? 0));
        if ($product->automotiveService?->item_type === 'service') {
            $product->setAttribute('stock', 999999);
            return;
        }
        $product->setAttribute('stock', (float) $product->warehouses->sum(fn ($warehouse) => (float) $warehouse->pivot->stock));
    });

    return ProductResource::collection($products);
}

    private function syncBranchPrice(Product $product, Request $request, mixed $price): void
    {
        if ($price === null) return;

        $branchId = (int) ($request->user()?->branch_id
            ?: (app()->bound('currentBranchId') ? app('currentBranchId') : 0));
        $tenantId = (int) ($request->user()?->tenant_id
            ?: (app()->bound('currentTenantId') ? app('currentTenantId') : $product->tenant_id));
        if (!$branchId || !$tenantId) return;

        ProductBranchPrice::updateOrCreate(
            ['tenant_id' => $tenantId, 'branch_id' => $branchId, 'product_id' => $product->id],
            ['price' => round((float) $price, 2)]
        );
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

            $total = (float) ProductWarehouse::withoutGlobalScopes()
    ->where('product_id', $productId)
    ->sum('stock');

$product->update([
    'stock' => $total,            // مجموع كل المخازن، وليس كمية مخزن واحد
    'cost' => $request->cost,
    'beginning_balance' => true,
]);

return ['stock' => $total, 'warehouse_stock' => $newStock, 'warehouse_id' => $targetWarehouseId];
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
/**
 * ✅ تطبيع مدخلات warehouse_stocks من أي شكل:
 *    - array بمفاتيح int
 *    - array بمفاتيح string
 *    - JSON string
 *    - object من Laravel
 */
private function normalizeWarehouseStocksInput(mixed $input): array
{
    if (is_string($input)) {
        $input = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                'Invalid warehouse_stocks JSON: ' . json_last_error_msg()
            );
        }
    }

    if (is_object($input)) {
        $input = (array) $input;
    }

    if (!is_array($input)) {
        throw new \InvalidArgumentException(
            'warehouse_stocks must be an object or array.'
        );
    }

    $normalized = [];

    foreach ($input as $warehouseId => $stock) {
        $originalKey = (string) $warehouseId;

        if (
            !ctype_digit($originalKey) ||
            (int) $originalKey <= 0
        ) {
            throw new \InvalidArgumentException(
                "Invalid warehouse ID in warehouse_stocks. Received key: {$originalKey}"
            );
        }

        $warehouseId = (int) $originalKey;

        if (!is_numeric($stock) || !is_finite((float) $stock) || (float) $stock < 0) {
            throw new \InvalidArgumentException(
                "Invalid stock value for warehouse {$warehouseId}."
            );
        }

        $normalized[(string) $warehouseId] = (float) $stock;
    }

    return $normalized;
}



private function linkWarehouses(
    Product $product,
    ?array $warehouseIds,
    array $warehouseStocks = [],
    bool $sync = false
): void {

    $tenantId = (int) $product->tenant_id;
    $productId = (int) $product->id;
    $table = 'product_warehouse';

    // توحيد مفاتيح الكميات
    $normalizedStocks = [];

    foreach ($warehouseStocks as $key => $value) {
        $warehouseId = (int) $key;

        if ($warehouseId <= 0) {
            throw new \InvalidArgumentException('Invalid warehouse ID.');
        }

        if (!is_numeric($value) || (float) $value < 0) {
            throw new \InvalidArgumentException(
                "Invalid stock for warehouse {$warehouseId}."
            );
        }

        $normalizedStocks[(string) $warehouseId] = (float) $value;
    }

    // لو لم تُرسل قائمة المخازن، نستخدم المخازن الموجودة
    if ($warehouseIds === null) {
        $warehouseIds = DB::table($table)
            ->where('product_id', $productId)
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($warehouseIds)) {
            $mainWarehouse = DB::table('warehouses')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->orderByDesc('main_branch')
                ->orderBy('id')
                ->first();

            $warehouseIds = $mainWarehouse
                ? [(int) $mainWarehouse->id]
                : [];
        }
    }

    // إضافة أي مخزن تم إرسال كمية له
    $warehouseIds = array_values(array_unique(array_merge(
        array_map('intval', $warehouseIds),
        array_map('intval', array_keys($normalizedStocks))
    )));

    // التحقق من أن كل المخازن تابعة لنفس Tenant
    $ownedWarehouseIds = empty($warehouseIds)
        ? []
        : DB::table('warehouses')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereIn('id', $warehouseIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

    if (count($ownedWarehouseIds) !== count($warehouseIds)) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'warehouse_ids' => [
                'One or more warehouses do not belong to this tenant.'
            ],
        ]);
    }

    // تحديث أو إنشاء الكميات
    foreach ($ownedWarehouseIds as $warehouseId) {

        $warehouseKey = (string) $warehouseId;

        $existingRows = DB::table($table)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $keepRow = $existingRows->first();

        // حذف التكرارات مع الاحتفاظ بأول سجل
        if ($existingRows->count() > 1) {
            $duplicateIds = $existingRows
                ->slice(1)
                ->pluck('id')
                ->all();

            DB::table($table)
                ->whereIn('id', $duplicateIds)
                ->delete();
        }

        // لو الكمية اتبعتت نستخدمها.
        // لو لم تُرسل نحافظ على الكمية القديمة.
        if (array_key_exists($warehouseKey, $normalizedStocks)) {
            $stock = $normalizedStocks[$warehouseKey];
        } elseif ($keepRow) {
            $stock = (float) $keepRow->stock;
        } else {
            $stock = 0;
        }

        $now = now();

        if ($keepRow) {
            DB::table($table)
                ->where('id', $keepRow->id)
                ->update([
                    'tenant_id' => $tenantId,
                    'stock' => $stock,
                    'cost' => $keepRow->cost ?? ($product->cost ?? 0),
                    'updated_at' => $now,
                ]);
        } else {
            DB::table($table)->insert([
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'stock' => $stock,
                'cost' => $product->cost ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // التحقق من الكمية المحفوظة
        $savedStock = (float) DB::table($table)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->sum('stock');

        if (abs($savedStock - $stock) > 0.0001) {
            throw new \RuntimeException(
                "Warehouse stock verification failed. " .
                "Product: {$productId}, " .
                "Warehouse: {$warehouseId}, " .
                "Target: {$stock}, Saved: {$savedStock}"
            );
        }
    }

    // حذف المخازن غير المحددة عند الطلب الصريح فقط
    if ($sync) {
        DB::table($table)
            ->where('product_id', $productId)
            ->whereNotIn('warehouse_id', $ownedWarehouseIds)
            ->delete();
    }

    // تحديث إجمالي مخزون المنتج
    $totalStock = (float) DB::table($table)
        ->where('product_id', $productId)
        ->sum('stock');

    DB::table('products')
        ->where('id', $productId)
        ->update([
            'stock' => $totalStock,
            'updated_at' => now(),
        ]);

    \Log::info('LINK WAREHOUSES COMPLETED', [
        'product_id' => $productId,
        'tenant_id' => $tenantId,
        'total_stock' => $totalStock,
        'warehouse_stocks' => DB::table($table)
            ->where('product_id', $productId)
            ->pluck('stock', 'warehouse_id')
            ->toArray(),
        'sync' => $sync,
    ]);
}



}
