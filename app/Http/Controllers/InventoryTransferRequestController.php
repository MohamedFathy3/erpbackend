<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\InventoryTransferRequest;
use App\Models\Product;
use App\Models\Warehouse;
use App\Notifications\InventoryTransferRequestNotification;
use App\Services\InventoryMovementService;
use App\Services\InventoryTransferPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class InventoryTransferRequestController extends Controller
{
    public function branches(Request $request)
    {
        $user = $this->actor($request);
        $this->requirePermission($user, 'inventory.transfer_requests.view');
        $tenantId = $this->tenantId($user);
        $data = $request->validate([
            'destination_branch_id' => ['nullable', 'integer'],
        ]);
        $destinationBranchId = $user instanceof Employee
            ? (int) $user->branch_id
            : (int) ($data['destination_branch_id'] ?? 0);

        abort_unless($destinationBranchId > 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'يجب تحديد الفرع المستلم.');
        abort_unless($this->branchHasTenantWarehouse($destinationBranchId, $tenantId), Response::HTTP_NOT_FOUND, 'Branch not found.');

        $branchQuery = DB::table('branches as branches')
            ->join('warehouses', 'warehouses.branch_id', '=', 'branches.id')
            ->join('product_warehouse', 'product_warehouse.warehouse_id', '=', 'warehouses.id')
            ->join('products', 'products.id', '=', 'product_warehouse.product_id')
            ->where('branches.active', true)
            ->whereNull('branches.deleted_at')
            ->where('warehouses.tenant_id', $tenantId)
            ->where('warehouses.active', true)
            ->whereNull('warehouses.deleted_at')
            ->where('products.tenant_id', $tenantId)
            ->where('products.active', true)
            ->whereNull('products.deleted_at')
            ->where('product_warehouse.stock', '>', 0)
            ->where('branches.id', '<>', $destinationBranchId);
        if (Schema::hasColumn('branches', 'tenant_id')) {
            $branchQuery->where('branches.tenant_id', $tenantId);
        }
        $branchIds = $branchQuery->distinct()->pluck('branches.id');

        $branches = Branch::query()
            ->whereIn('id', $branchIds)
            ->where('active', true)
            ->get(['id', 'name']);

        return response()->json(['data' => $branches]);
    }

    public function products(Request $request)
    {
        $user = $this->actor($request);
        $this->requirePermission($user, 'inventory.transfer_requests.view');
        $tenantId = $this->tenantId($user);
        $data = $request->validate([
            'source_branch_id' => ['required', 'integer'],
            'destination_branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);
        $destinationBranchId = $user instanceof Employee
            ? (int) $user->branch_id
            : (int) ($data['destination_branch_id'] ?? 0);
        abort_unless($destinationBranchId > 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'يجب تحديد الفرع المستلم.');
        abort_unless($this->branchHasTenantWarehouse($destinationBranchId, $tenantId), Response::HTTP_NOT_FOUND, 'Branch not found.');
        abort_unless((int) $data['source_branch_id'] !== $destinationBranchId, Response::HTTP_UNPROCESSABLE_ENTITY, 'اختر فرعاً مختلفاً عن فرعك لطلب النقل.');

        abort_unless($this->branchHasTenantWarehouse((int) $data['source_branch_id'], $tenantId), Response::HTTP_NOT_FOUND, 'Branch not found.');

        $products = Product::query()
            ->withoutGlobalScope('branch')
            ->where('products.tenant_id', $tenantId)
            ->where('active', true)
            ->when($data['category_id'] ?? null, fn ($q, $category) => $q->where('category_id', $category))
            ->when($data['search'] ?? null, function ($q, $search): void {
                $hasArabicName = Schema::hasColumn('products', 'name_ar');
                $q->where(function ($inner) use ($search, $hasArabicName): void {
                    $inner->where('name', 'like', "%{$search}%");
                    if ($hasArabicName) $inner->orWhere('name_ar', 'like', "%{$search}%");
                    $inner->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->whereHas('warehouses', function ($q) use ($data, $tenantId): void {
                $q->withoutGlobalScope('branch')
                    ->where('warehouses.tenant_id', $tenantId)
                    ->where('warehouses.branch_id', $data['source_branch_id'])
                    ->where('active', true)
                    ->where('product_warehouse.stock', '>', 0);
            })
            ->with(['warehouses' => function ($q) use ($data, $tenantId): void {
                $q->withoutGlobalScope('branch')
                    ->where('warehouses.tenant_id', $tenantId)
                    ->where('warehouses.branch_id', $data['source_branch_id'])
                    ->where('active', true)
                    ->withPivot(['stock', 'cost']);
            }])
            ->limit(100)
            ->get();

        $result = $products->map(function (Product $product): array {
            $warehouse = $product->warehouses->sortByDesc(fn ($item) => (float) $item->pivot->stock)->first();
            return [
                'id' => $product->id,
                'name' => $product->name,
                'name_ar' => $product->name_ar,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'stock' => (float) ($warehouse?->pivot?->stock ?? 0),
                'source_warehouse_id' => $warehouse?->id,
                'source_warehouse_name' => $warehouse?->name,
                'category_id' => $product->category_id,
            ];
        })->values();

        return response()->json(['data' => $result]);
    }

    public function index(Request $request)
    {
        $user = $this->actor($request);
        $this->requirePermission($user, 'inventory.transfer_requests.view');

        $requests = InventoryTransferRequest::query()
            ->where('tenant_id', $this->tenantId($user))
            ->with(['product:id,name,sku', 'fromBranch:id,name', 'toBranch:id,name', 'fromWarehouse:id,name', 'toWarehouse:id,name', 'requester:id,name', 'journalEntry:id,entry_number,status'])
            ->when(!$this->isManager($user), fn ($q) => $q->where(function ($inner) use ($user): void {
                $inner->where('requested_by', $user->id)->orWhere('to_branch_id', $user->branch_id);
            }))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return response()->json(['data' => $requests]);
    }

    public function store(Request $request)
    {
        $user = $this->actor($request);
        $this->requirePermission($user, 'inventory.transfer_requests.create');
        $tenantId = $this->tenantId($user);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'from_branch_id' => ['required', 'integer'],
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['nullable', 'integer'],
            'to_branch_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $destinationBranchId = $user instanceof Employee ? (int) $user->branch_id : (int) ($data['to_branch_id'] ?? 0);
        abort_unless($destinationBranchId > 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'يجب تحديد الفرع المستلم.');
        abort_unless((int) $data['from_branch_id'] !== $destinationBranchId, Response::HTTP_UNPROCESSABLE_ENTITY, 'اختر فرعاً مختلفاً عن فرعك لطلب النقل.');
        abort_unless($this->branchHasTenantWarehouse($destinationBranchId, $tenantId), Response::HTTP_UNPROCESSABLE_ENTITY, 'لا يوجد مخزن نشط مرتبط بفرعك.');

        $product = Product::query()->withoutGlobalScope('branch')
            ->where('products.tenant_id', $tenantId)
            ->where('active', true)
            ->findOrFail($data['product_id']);
        $sourceWarehouse = Warehouse::query()->withoutGlobalScope('branch')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $data['from_branch_id'])
            ->where('active', true)
            ->find($data['from_warehouse_id']);
        abort_unless($sourceWarehouse && $this->branchHasTenantWarehouse((int) $data['from_branch_id'], $tenantId), Response::HTTP_UNPROCESSABLE_ENTITY, 'المخزن لا يتبع الفرع المصدر.');

        $destinationWarehouse = isset($data['to_warehouse_id'])
            ? Warehouse::query()->withoutGlobalScope('branch')->where('tenant_id', $tenantId)->whereKey($data['to_warehouse_id'])->where('branch_id', $destinationBranchId)->where('active', true)->first()
            : Warehouse::query()->withoutGlobalScope('branch')->where('tenant_id', $tenantId)->where('branch_id', $destinationBranchId)->where('active', true)->orderByDesc('main_branch')->first();
        abort_unless($destinationWarehouse, Response::HTTP_UNPROCESSABLE_ENTITY, 'لا يوجد مخزن نشط مرتبط بفرعك.');

        $transfer = DB::transaction(function () use ($data, $tenantId, $product, $sourceWarehouse, $destinationWarehouse, $destinationBranchId, $user): InventoryTransferRequest {
            $available = (float) DB::table('product_warehouse')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $sourceWarehouse->id)
                ->lockForUpdate()
                ->value('stock');
            abort_unless($available >= (float) $data['quantity'], Response::HTTP_UNPROCESSABLE_ENTITY, 'الكمية المطلوبة أكبر من المخزون المتاح في المخزن المصدر.');

            return InventoryTransferRequest::create([
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'from_branch_id' => $data['from_branch_id'],
                'from_warehouse_id' => $sourceWarehouse->id,
                'to_branch_id' => $destinationBranchId,
                'to_warehouse_id' => $destinationWarehouse->id,
                'quantity' => $data['quantity'],
                'requested_by' => $user instanceof Employee ? $user->id : null,
                'note' => $data['note'] ?? null,
                'status' => 'pending',
            ]);
        });

        // employees has soft deletes but no is_active database column.
        Employee::query()->with('role')->where('tenant_id', $user->tenant_id)->where('branch_id', $destinationBranchId)->get()->each(function (Employee $employee) use ($transfer): void {
            $role = strtolower((string) $employee->role?->name);
            if ($this->isManager($employee) || str_contains($role, 'cashier')) $employee->notify(new InventoryTransferRequestNotification($transfer));
        });
        return response()->json(['data' => $transfer->load(['product', 'fromBranch', 'toBranch', 'fromWarehouse', 'toWarehouse'])], Response::HTTP_CREATED);
    }

    public function approve(Request $request, InventoryTransferRequest $transferRequest)
    {
        $user = $this->actor($request);
        $this->requirePermission($user, 'inventory.transfer_requests.approve');
        $tenantId = $this->tenantId($user);
        abort_unless((int) $transferRequest->tenant_id === $tenantId, Response::HTTP_NOT_FOUND, 'Transfer request not found.');
        abort_unless(($user instanceof Employee && (int) $transferRequest->to_branch_id === (int) $user->branch_id) || $this->isManager($user), Response::HTTP_FORBIDDEN, 'لا يمكنك اعتماد طلب هذا الفرع.');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $transfer = DB::transaction(function () use ($transferRequest, $user, $data, $tenantId): InventoryTransferRequest {
            $transfer = InventoryTransferRequest::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($transferRequest->id);
            abort_unless($transfer->status === 'pending', Response::HTTP_UNPROCESSABLE_ENTITY, 'هذا الطلب تمت معالجته مسبقاً.');
            $product = Product::query()->withoutGlobalScope('branch')
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($transfer->product_id);
            $sourceStock = DB::table('product_warehouse')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $transfer->from_warehouse_id)
                ->lockForUpdate()
                ->first();
            $destinationStock = DB::table('product_warehouse')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $transfer->to_warehouse_id)
                ->lockForUpdate()
                ->first();
            $warehouseCost = (float) ($sourceStock?->cost ?? 0);
            $unitCost = $warehouseCost > 0 ? $warehouseCost : (float) ($product->cost ?? 0);

            $outMovement = app(InventoryMovementService::class)->apply([
                'product_id' => $transfer->product_id,
                'warehouse_id' => $transfer->from_warehouse_id,
                'branch_id' => $transfer->from_branch_id,
                'movement_type' => 'branch_transfer_out',
                'quantity_delta' => -(float) $transfer->quantity,
                'unit_cost' => $unitCost,
                'reference_type' => InventoryTransferRequest::class,
                'reference_id' => $transfer->id,
                'notes' => 'نقل مخزون إلى فرع آخر',
            ], $tenantId);
            $inMovement = app(InventoryMovementService::class)->apply([
                'product_id' => $transfer->product_id,
                'warehouse_id' => $transfer->to_warehouse_id,
                'branch_id' => $transfer->to_branch_id,
                'movement_type' => 'branch_transfer_in',
                'quantity_delta' => (float) $transfer->quantity,
                'unit_cost' => $unitCost,
                'reference_type' => InventoryTransferRequest::class,
                'reference_id' => $transfer->id,
                'notes' => 'استلام مخزون من فرع آخر',
            ], $tenantId);
            $destinationQuantityBefore = (float) ($destinationStock?->stock ?? 0);
            $destinationCostBefore = (float) ($destinationStock?->cost ?? 0);
            $destinationQuantityAfter = $destinationQuantityBefore + (float) $transfer->quantity;
            if ($destinationQuantityAfter > 0 && $unitCost > 0) {
                $destinationAverageCost = $destinationQuantityBefore > 0 && $destinationCostBefore > 0
                    ? (($destinationQuantityBefore * $destinationCostBefore) + ((float) $transfer->quantity * $unitCost)) / $destinationQuantityAfter
                    : $unitCost;
                DB::table('product_warehouse')
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $transfer->to_warehouse_id)
                    ->update(['cost' => round($destinationAverageCost, 2), 'updated_at' => now()]);
            }
            $transfer->update(['status' => 'approved', 'approved_by' => $user instanceof Employee ? $user->id : null, 'approved_at' => now(), 'note' => $data['note'] ?? $transfer->note]);
            app(InventoryTransferPostingService::class)->post($transfer, $unitCost, [$outMovement->id, $inMovement->id]);
            return $transfer->refresh();
        });

        $transfer->load(['product', 'fromBranch', 'toBranch', 'fromWarehouse', 'toWarehouse', 'journalEntry']);
        if ($transfer->requester) $transfer->requester->notify(new InventoryTransferRequestNotification($transfer, 'approved'));
        return response()->json(['data' => $transfer]);
    }

    public function reject(Request $request, InventoryTransferRequest $transferRequest)
    {
        $user = $this->actor($request);
        $this->requirePermission($user, 'inventory.transfer_requests.approve');
        abort_unless((int) $transferRequest->tenant_id === $this->tenantId($user), Response::HTTP_NOT_FOUND, 'Transfer request not found.');
        abort_unless(($user instanceof Employee && (int) $transferRequest->to_branch_id === (int) $user->branch_id) || $this->isManager($user), Response::HTTP_FORBIDDEN, 'لا يمكنك معالجة طلب هذا الفرع.');
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
        abort_unless($transferRequest->status === 'pending', Response::HTTP_UNPROCESSABLE_ENTITY, 'هذا الطلب تمت معالجته مسبقاً.');
        $transferRequest->update(['status' => 'rejected', 'approved_by' => $user instanceof Employee ? $user->id : null, 'approved_at' => now(), 'rejection_reason' => $data['rejection_reason']]);
        $transferRequest->load(['product', 'fromBranch', 'toBranch']);
        if ($transferRequest->requester) $transferRequest->requester->notify(new InventoryTransferRequestNotification($transferRequest, 'rejected'));
        return response()->json(['data' => $transferRequest]);
    }

    private function actor(Request $request): Employee|Admin
    {
        $user = $request->user();
        abort_unless($user instanceof Employee || $user instanceof Admin, Response::HTTP_FORBIDDEN, 'Employee or Admin account required.');
        return $user;
    }

    private function requirePermission(Employee|Admin $employee, string $permission): void
    {
        abort_unless((bool) $employee->super_admin || $this->isAdmin($employee) || $employee->hasPermission($permission), Response::HTTP_FORBIDDEN, 'ليس لديك صلاحية لهذا الإجراء.');
    }

    private function isAdmin(Employee|Admin $employee): bool
    {
        return $employee instanceof Admin
            || str_contains(strtolower((string) $employee->role?->name), 'admin');
    }

    private function isManager(Employee|Admin $employee): bool
    {
        if ($employee instanceof Admin) return true;
        $role = strtolower((string) $employee->role?->name);
        return str_contains($role, 'manager') || str_contains($role, 'admin') || (bool) $employee->super_admin;
    }

    private function tenantId(Employee|Admin $user): int
    {
        $tenantId = (int) ($user->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : 0));
        abort_unless($tenantId > 0, Response::HTTP_FORBIDDEN, 'A tenant context is required.');
        return $tenantId;
    }

    private function branchHasTenantWarehouse(int $branchId, int $tenantId): bool
    {
        $query = DB::table('branches')
            ->join('warehouses', 'warehouses.branch_id', '=', 'branches.id')
            ->where('branches.id', $branchId)
            ->where('branches.active', true)
            ->whereNull('branches.deleted_at')
            ->where('warehouses.tenant_id', $tenantId)
            ->where('warehouses.active', true)
            ->whereNull('warehouses.deleted_at');
        if (Schema::hasColumn('branches', 'tenant_id')) {
            $query->where('branches.tenant_id', $tenantId);
        }
        return $query->exists();
    }
}
