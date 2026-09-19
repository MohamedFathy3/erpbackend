<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Admin;
use App\Models\InventoryTransferRequest;
use App\Models\Product;
use App\Models\Warehouse;
use App\Notifications\InventoryTransferRequestNotification;
use App\Services\InventoryMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InventoryTransferRequestController extends Controller
{
    public function products(Request $request)
    {
        $user = $this->actor($request);
        $data = $request->validate([
            'source_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $products = Product::query()
            ->where('active', true)
            ->when($data['category_id'] ?? null, fn ($q, $category) => $q->where('category_id', $category))
            ->when($data['search'] ?? null, function ($q, $search): void {
                $q->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('name_ar', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->whereHas('warehouses', function ($q) use ($data): void {
                // whereHas receives a normal query builder, not the
                // BelongsToMany relation, so wherePivot() becomes a dynamic
                // column named "pivot" and generates invalid SQL.
                $q->where('branch_id', $data['source_branch_id'])
                    ->where('active', true)
                    ->where('product_warehouse.stock', '>', 0);
            })
            ->with(['warehouses' => function ($q) use ($data): void {
                $q->where('branch_id', $data['source_branch_id'])
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
            ->with(['product:id,name,name_ar,sku', 'fromBranch:id,name', 'toBranch:id,name', 'fromWarehouse:id,name', 'toWarehouse:id,name', 'requester:id,name'])
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
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'from_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'to_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $destinationBranchId = $user instanceof Employee ? (int) $user->branch_id : (int) ($data['to_branch_id'] ?? 0);
        abort_unless($destinationBranchId > 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'يجب تحديد الفرع المستلم.');
        abort_unless((int) $data['from_branch_id'] !== $destinationBranchId, Response::HTTP_UNPROCESSABLE_ENTITY, 'اختر فرعاً مختلفاً عن فرعك لطلب النقل.');
        $destinationWarehouse = isset($data['to_warehouse_id'])
            ? Warehouse::query()->whereKey($data['to_warehouse_id'])->where('branch_id', $destinationBranchId)->first()
            : Warehouse::query()->where('branch_id', $destinationBranchId)->where('active', true)->orderByDesc('main_branch')->first();
        abort_unless($destinationWarehouse, Response::HTTP_UNPROCESSABLE_ENTITY, 'لا يوجد مخزن نشط مرتبط بفرعك.');

        $sourceWarehouse = Warehouse::query()->whereKey($data['from_warehouse_id'])->where('branch_id', $data['from_branch_id'])->first();
        abort_unless($sourceWarehouse, Response::HTTP_UNPROCESSABLE_ENTITY, 'المخزن لا يتبع الفرع المصدر.');
        $available = (float) DB::table('product_warehouse')->where('product_id', $data['product_id'])->where('warehouse_id', $sourceWarehouse->id)->lockForUpdate()->value('stock');
        abort_unless($available >= (float) $data['quantity'], Response::HTTP_UNPROCESSABLE_ENTITY, 'الكمية المطلوبة أكبر من المخزون المتاح في المخزن المصدر.');

        $transfer = InventoryTransferRequest::create([
            'tenant_id' => $user->tenant_id,
            'product_id' => $data['product_id'],
            'from_branch_id' => $data['from_branch_id'],
            'from_warehouse_id' => $sourceWarehouse->id,
            'to_branch_id' => $destinationBranchId,
            'to_warehouse_id' => $destinationWarehouse->id,
            'quantity' => $data['quantity'],
            'requested_by' => $user instanceof Employee ? $user->id : null,
            'note' => $data['note'] ?? null,
            'status' => 'pending',
        ]);

        Employee::query()->where('tenant_id', $user->tenant_id)->where('branch_id', $destinationBranchId)->where('is_active', true)->get()->each(function (Employee $employee) use ($transfer): void {
            $role = strtolower((string) $employee->role?->name);
            if ($this->isManager($employee) || str_contains($role, 'cashier')) $employee->notify(new InventoryTransferRequestNotification($transfer));
        });

        return response()->json(['data' => $transfer->load(['product', 'fromBranch', 'toBranch', 'fromWarehouse', 'toWarehouse'])], Response::HTTP_CREATED);
    }

    public function approve(Request $request, InventoryTransferRequest $transferRequest)
    {
        $user = $this->actor($request);
        $this->requirePermission($user, 'inventory.transfer_requests.approve');
        abort_unless(($user instanceof Employee && $transferRequest->to_branch_id === $user->branch_id) || $this->isManager($user), Response::HTTP_FORBIDDEN, 'لا يمكنك اعتماد طلب هذا الفرع.');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $transfer = DB::transaction(function () use ($transferRequest, $user, $data): InventoryTransferRequest {
            $transfer = InventoryTransferRequest::query()->lockForUpdate()->findOrFail($transferRequest->id);
            abort_unless($transfer->status === 'pending', Response::HTTP_UNPROCESSABLE_ENTITY, 'هذا الطلب تمت معالجته مسبقاً.');
            app(InventoryMovementService::class)->apply([
                'product_id' => $transfer->product_id,
                'warehouse_id' => $transfer->from_warehouse_id,
                'branch_id' => $transfer->from_branch_id,
                'movement_type' => 'branch_transfer_out',
                'quantity_delta' => -(float) $transfer->quantity,
                'reference_type' => InventoryTransferRequest::class,
                'reference_id' => $transfer->id,
                'notes' => 'نقل مخزون إلى فرع آخر',
            ]);
            app(InventoryMovementService::class)->apply([
                'product_id' => $transfer->product_id,
                'warehouse_id' => $transfer->to_warehouse_id,
                'branch_id' => $transfer->to_branch_id,
                'movement_type' => 'branch_transfer_in',
                'quantity_delta' => (float) $transfer->quantity,
                'reference_type' => InventoryTransferRequest::class,
                'reference_id' => $transfer->id,
                'notes' => 'استلام مخزون من فرع آخر',
            ]);
            $transfer->update(['status' => 'approved', 'approved_by' => $user instanceof Employee ? $user->id : null, 'approved_at' => now(), 'note' => $data['note'] ?? $transfer->note]);
            return $transfer;
        });

        $transfer->load(['product', 'fromBranch', 'toBranch', 'fromWarehouse', 'toWarehouse']);
        if ($transfer->requester) $transfer->requester->notify(new InventoryTransferRequestNotification($transfer, 'approved'));
        return response()->json(['data' => $transfer]);
    }

    public function reject(Request $request, InventoryTransferRequest $transferRequest)
    {
        $user = $this->actor($request);
        $this->requirePermission($user, 'inventory.transfer_requests.approve');
        abort_unless(($user instanceof Employee && $transferRequest->to_branch_id === $user->branch_id) || $this->isManager($user), Response::HTTP_FORBIDDEN, 'لا يمكنك معالجة طلب هذا الفرع.');
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
        return str_contains(strtolower((string) $employee->role?->name), 'admin');
    }

    private function isManager(Employee|Admin $employee): bool
    {
        $role = strtolower((string) $employee->role?->name);
        return str_contains($role, 'manager') || str_contains($role, 'admin') || (bool) $employee->super_admin;
    }
}
