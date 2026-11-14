<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReturnInvoiceResource;
use App\Models\Admin;
use App\Models\AutomotiveService;
use App\Models\CashierShift;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ReturnInvoice;
use App\Models\ReturnItem;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Services\InventoryMovementService;
use App\Services\AutomotiveStockService;
use App\Services\PosAccountingPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReturnInvoiceController extends Controller
{
    public function storeReturn(Request $request, PosAccountingPostingService $posting)
    {
        $data = $request->validate([
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.invoice_item_id' => ['nullable', 'integer'],
            'items.*.automotive_service_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.color' => ['nullable', 'string', 'max:100'],
            'items.*.size' => ['nullable', 'string', 'max:100'],
            'payments' => ['nullable', 'array'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'refund_method' => ['required', 'string', 'in:cash,card,wallet,credit,none'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::beginTransaction();

        try {
            // ============================================================
            // 1. جلب الفاتورة الأصلية
            // ============================================================
            $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
            if ($invoiceNumber === '' && collect($data['items'])->contains(fn ($item) => empty($item['product_id']))) {
                throw ValidationException::withMessages([
                    'invoice_number' => 'رقم الفاتورة مطلوب عند إرجاع خدمة أو بند فاتورة محدد.',
                ]);
            }

            $invoiceQuery = Invoice::with('items');
            if ($invoiceNumber !== '') {
                $digits = preg_replace('/\D+/', '', $invoiceNumber);
                $invoiceQuery->where(fn ($query) => $query->where('invoice_number', $invoiceNumber)
                    ->when($digits !== '', fn ($suffixQuery) => $suffixQuery->orWhere('invoice_number', 'like', '%' . $digits)));
            } else {
                foreach (collect($data['items'])->pluck('product_id')->filter()->unique() as $productId) {
                    $invoiceQuery->whereHas('items', fn ($query) => $query->where('product_id', $productId));
                }
            }
            $invoice = $invoiceQuery->latest('id')->lockForUpdate()->firstOrFail();
            $isComplimentary = (bool) $invoice->is_complimentary;

            // ============================================================
            // 2. التحقق من الكميات
            // ============================================================
            $itemsTotal = 0;
            $invoiceItemsTotal = (float) $invoice->items->sum(fn ($invoiceItem) => (float) ($invoiceItem->total ?? ((float) $invoiceItem->price * (float) $invoiceItem->quantity)));
            $invoiceValueFactor = $invoiceItemsTotal > 0
                ? (float) $invoice->total_amount / $invoiceItemsTotal
                : 0;
            $plannedItems = [];
            $plannedQuantities = [];

            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) && empty($item['invoice_item_id'])) {
                    throw ValidationException::withMessages([
                        'items' => 'كل بند مرتجع يجب أن يحدد المنتج أو بند الفاتورة الأصلي.',
                    ]);
                }

                $invoiceItem = !empty($item['invoice_item_id'])
                    ? $invoice->items->firstWhere('id', $item['invoice_item_id'])
                    : $invoice->items->first(function ($invoiceItem) use ($item) {
                        return (int) $invoiceItem->product_id === (int) $item['product_id']
                            && (!array_key_exists('color', $item) || ($invoiceItem->color ?? null) === ($item['color'] ?? null))
                            && (!array_key_exists('size', $item) || ($invoiceItem->size ?? null) === ($item['size'] ?? null));
                    });

                if (!$invoiceItem) {
                    throw new \RuntimeException("المنتج غير موجود في الفاتورة {$invoice->invoice_number}");
                }

                if ($invoiceItem->item_type === 'service'
                    && !empty($item['automotive_service_id'])
                    && (int) $invoiceItem->automotive_service_id !== (int) $item['automotive_service_id']) {
                    throw new \RuntimeException('خدمة السيارات المحددة لا تطابق بند الفاتورة.');
                }
                if (!empty($item['product_id'])
                    && (int) $invoiceItem->product_id !== (int) $item['product_id']
                    && (int) $invoiceItem->automotive_service_id !== (int) ($item['automotive_service_id'] ?? 0)) {
                    throw new \RuntimeException('المنتج المحدد لا يطابق بند الفاتورة.');
                }

                $alreadyReturnedQuery = ReturnItem::whereHas('returnInvoice', function ($query) use ($invoice) {
                    $query->where('invoice_id', $invoice->id)
                        ->where(fn ($activeQuery) => $activeQuery->whereNull('workflow_status')
                            ->orWhere('workflow_status', '!=', 'cancelled'));
                });
                if ($invoiceItem->product_id) {
                    $alreadyReturnedQuery->where('product_id', $invoiceItem->product_id)
                        ->where('color', $invoiceItem->color)
                        ->where('size', $invoiceItem->size);
                } else {
                    $alreadyReturnedQuery->where('invoice_item_id', $invoiceItem->id);
                }
                $alreadyReturned = $alreadyReturnedQuery->sum('quantity');

                $plannedQuantities[$invoiceItem->id] = ($plannedQuantities[$invoiceItem->id] ?? 0) + (float) $item['quantity'];
                $remaining = (float) $invoiceItem->quantity - (float) $alreadyReturned;

                if ($plannedQuantities[$invoiceItem->id] > $remaining) {
                    throw new \RuntimeException("الكمية المرتجعة أكبر من المتبقي للمنتج {$invoiceItem->product_name}");
                }

                $lineTotal = (float) ($invoiceItem->total ?? ((float) $invoiceItem->price * (float) $invoiceItem->quantity));
                $returnLineTotal = $invoiceItem->quantity > 0
                    ? round($lineTotal * (float) $item['quantity'] / (float) $invoiceItem->quantity * $invoiceValueFactor, 2)
                    : 0;
                $itemsTotal += $returnLineTotal;
                $plannedItems[] = [
                    'request' => $item,
                    'invoice_item' => $invoiceItem,
                    'return_line_total' => $returnLineTotal,
                ];
            }

            $isFullReturn = $invoice->items->every(function ($invoiceItem) use ($invoice, $plannedQuantities) {
                $returnedQuery = ReturnItem::whereHas('returnInvoice', function ($query) use ($invoice) {
                    $query->where('invoice_id', $invoice->id)
                        ->where(fn ($activeQuery) => $activeQuery->whereNull('workflow_status')
                            ->orWhere('workflow_status', '!=', 'cancelled'));
                });
                if ($invoiceItem->product_id) {
                    $returnedQuery->where('product_id', $invoiceItem->product_id)
                        ->where('color', $invoiceItem->color)
                        ->where('size', $invoiceItem->size);
                } else {
                    $returnedQuery->where('invoice_item_id', $invoiceItem->id);
                }

                $alreadyReturned = (float) $returnedQuery->sum('quantity');
                $beingReturned = (float) ($plannedQuantities[$invoiceItem->id] ?? 0);
                return $alreadyReturned + $beingReturned >= (float) $invoiceItem->quantity;
            });
            if ($isFullReturn) {
                $fullReturnAmount = (float) $invoice->net_amount;
                $lastItemIndex = array_key_last($plannedItems);
                if ($lastItemIndex !== null) {
                    $plannedItems[$lastItemIndex]['return_line_total'] += $fullReturnAmount - $itemsTotal;
                }
                $itemsTotal = $fullReturnAmount;
            }

            $total = $isComplimentary ? 0.0 : min((float) $invoice->net_amount, round($itemsTotal, 2));

            // Complimentary invoices never have money to refund, regardless
            // of what the client sends in the payments payload.
            $paymentsTotal = $isComplimentary ? 0.0 : (float) collect($data['payments'] ?? [])->sum('amount');

            if ($paymentsTotal > $total + 0.01) {
                throw new \RuntimeException('إجمالي المبلغ المسترد أكبر من قيمة المرتجع المستحقة.');
            }

            // ============================================================
            // 3. إنشاء المرتجع
            // ============================================================
            $return = ReturnInvoice::create([
                'invoice_id'      => $invoice->id,
                'total_amount'    => $total,
                'refunded_amount' => min($paymentsTotal, $total),
                'refund_method'   => $data['refund_method'],
                'reason'          => $data['reason'] ?? null,
            ]);

            // ============================================================
            // 4. حفظ البنود + إرجاع المخزون
            // ============================================================
            foreach ($plannedItems as $plannedItem) {
                $item = $plannedItem['request'];
                $invoiceItem = $plannedItem['invoice_item'];
                $service = $invoiceItem->item_type === 'service'
                    ? AutomotiveService::query()->where('tenant_id', $invoice->tenant_id)
                        ->findOrFail($invoiceItem->automotive_service_id)
                    : null;
                $productId = $invoiceItem->product_id ?? $service?->product_id;
                $product = $productId
                    ? Product::query()->withoutGlobalScope('branch')
                        ->where('tenant_id', $invoice->tenant_id)->findOrFail($productId)
                    : null;

                $return->items()->create([
                    'invoice_item_id' => $invoiceItem->id,
                    'product_id'   => $product?->id,
                    'automotive_service_id' => $service?->id,
                    'product_name' => $invoiceItem->product_name ?? $product?->name ?? $service?->name,
                    'color'        => $invoiceItem->color ?? ($item['color'] ?? null),
                    'size'         => $invoiceItem->size ?? ($item['size'] ?? null),
                    'quantity'     => $item['quantity'],
                    'price'        => $isComplimentary ? 0 : $invoiceItem->price,
                    'total'        => $isComplimentary ? 0 : $plannedItem['return_line_total'],
                ]);

                if ($service) {
                    $stockQuantity = (float) $item['quantity'] * (float) ($invoiceItem->meter_quantity ?: 1);
                    if ($service->item_type === 'product' && $stockQuantity > 0) {
                        app(AutomotiveStockService::class)->restore($service, $stockQuantity, [
                            'reference_type' => ReturnInvoice::class,
                            'reference_id' => $return->id,
                            'note' => "POS return for invoice {$invoice->invoice_number}",
                        ]);
                        if ($service->product_id) {
                            $this->restoreAutomotiveWarehouseStock($service, $invoice, $stockQuantity);
                        }
                    }
                } elseif ($product) {
                    $stockQuantity = (float) $item['quantity'] * (float) ($invoiceItem->meter_quantity ?: 1);
                    $this->restoreInventoryStock($product, $invoice, $return, $invoiceItem, $stockQuantity);
                }
            }

            // ============================================================
            // 5. Audit Log
            // ============================================================
            activity()
                ->performedOn($return)
                ->withProperties(['invoice_id' => $invoice->id])
                ->log('return_created');

            // ============================================================
            // 6. ✅ ربط المرتجع بالوردية
            //    - أولاً: ابحث عن وردية مفتوحة
            //    - ثانياً: لو مفيش، ابحث عن آخر وردية للمستخدم
            // ============================================================
            $user = auth()->user();

            $shift = CashierShift::where('status', 'open')
                ->where(function ($q) use ($user) {
                    if ($user instanceof Admin) {
                        $q->where('admin_id', $user->id);
                    } elseif ($user instanceof Employee) {
                        $q->where('employee_id', $user->id);
                    }
                })
                ->latest('opened_at')
                ->first();

            // لو مفيش وردية مفتوحة، استخدم آخر وردية (حتى لو مقفولة)
            if (!$shift) {
                $shift = CashierShift::where(function ($q) use ($user) {
                        if ($user instanceof Admin) {
                            $q->where('admin_id', $user->id);
                        } elseif ($user instanceof Employee) {
                            $q->where('employee_id', $user->id);
                        }
                    })
                    ->latest('opened_at')
                    ->first();

                Log::warning('No open shift. Attaching return to latest shift', [
                    'return_id' => $return->id,
                    'shift_id'  => $shift?->id,
                    'user_id'   => $user?->id,
                ]);
            }

            if ($shift) {
                // تحديث returns_amount في الوردية
                $shift->update([
                    'returns_amount' => ($shift->returns_amount ?? 0) + $paymentsTotal,
                ]);

                // ✅ ربط المرتجع بالوردية
                $return->update(['shift_id' => $shift->id]);
            } else {
                Log::error('No shift found at all for return', [
                    'return_id' => $return->id,
                    'user_id'   => $user?->id,
                ]);
            }

            $refundTreasuryId = $invoice->treasury_id;
            if ($user instanceof Employee && $user->treasury_id) {
                $refundTreasuryId = $user->treasury_id;
            } elseif ($shift?->employee?->treasury_id) {
                $refundTreasuryId = $shift->employee->treasury_id;
            }

            if ($paymentsTotal > 0 && strtolower((string) $return->refund_method) === 'cash') {
                $return->update(['refund_treasury_id' => $refundTreasuryId]);
            }

            // ============================================================
            // 7. رد نقدي من الخزينة
            // ============================================================
            if ($paymentsTotal > 0 && strtolower((string) $return->refund_method) === 'cash') {
                $treasury = $refundTreasuryId
                    ? Treasury::query()->withoutGlobalScope('branch')->lockForUpdate()->find($refundTreasuryId)
                    : null;

                if (!$treasury || (float) $treasury->balance < $paymentsTotal) {
                    $available = $treasury ? number_format((float) $treasury->balance, 2) : '0.00';
                    $requested = number_format($paymentsTotal, 2);
                    throw new \RuntimeException("رصيد خزينة الكاشير غير كافٍ. المتاح: {$available}، والمطلوب رده: {$requested}.");
                }

                $treasury->decrement('balance', $paymentsTotal);

                TreasuryTransaction::create([
                    'treasury_id'    => $treasury->id,
                    'reference_type' => ReturnInvoice::class,
                    'reference_id'   => $return->id,
                    'type'           => 'out',
                    'amount'         => $paymentsTotal,
                    'description'    => 'رد نقدي لمرتجع فاتورة POS ' . $invoice->invoice_number,
                ]);
            }

            // ============================================================
            // 8. القيد المالي
            // ============================================================
            $journal = $posting->postReturn($return);
            $return->update([
                'posting_journal_entry_id' => $journal?->id,
                'workflow_status'          => $journal ? 'posted' : 'pending_finance',
            ]);

            // ============================================================
            // 9. تحديث حالة الفاتورة الأصلية
            // ============================================================
            $invoice->refresh();

            $returnedAmount = (float) $invoice->returns()
                ->where(fn ($query) => $query->whereNull('workflow_status')
                    ->orWhere('workflow_status', '!=', 'cancelled'))
                ->sum('total_amount');

            $refundedAmount = (float) $invoice->returns()
                ->where(fn ($query) => $query->whereNull('workflow_status')
                    ->orWhere('workflow_status', '!=', 'cancelled'))
                ->sum('refunded_amount');

            $netAmount = $invoice->is_complimentary
                ? 0
                : max(0, (float) $invoice->total_amount - $returnedAmount);

            $netPaid = $invoice->is_complimentary
                ? 0
                : max(0, (float) $invoice->paid_amount - $refundedAmount);

            $invoice->update([
                'remaining_amount' => max(0, $netAmount - $netPaid),
                'status'           => $netPaid >= $netAmount
                    ? 'paid'
                    : ($netPaid > 0 ? 'partial' : 'unpaid'),
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'تم إنشاء فاتورة المرتجع بنجاح',
                'data'    => new ReturnInvoiceResource(
                    $return->load(['items', 'invoice'])
                ),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            if ($e instanceof ValidationException) {
                throw $e;
            }

            Log::error('Return creation failed', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function restoreAutomotiveWarehouseStock(
        AutomotiveService $service,
        Invoice $invoice,
        float $quantity
    ): void {
        $warehouseId = DB::table('product_warehouse')
            ->join('warehouses', 'warehouses.id', '=', 'product_warehouse.warehouse_id')
            ->where('product_warehouse.product_id', $service->product_id)
            ->where('warehouses.tenant_id', $invoice->tenant_id)
            ->whereNull('warehouses.deleted_at')
            ->when($invoice->branch_id, fn ($query) => $query->where('warehouses.branch_id', $invoice->branch_id))
            ->orderBy('warehouses.id')
            ->value('product_warehouse.warehouse_id');

        if (!$warehouseId) {
            $warehouseId = DB::table('product_warehouse')
                ->join('warehouses', 'warehouses.id', '=', 'product_warehouse.warehouse_id')
                ->where('product_warehouse.product_id', $service->product_id)
                ->where('warehouses.tenant_id', $invoice->tenant_id)
                ->whereNull('warehouses.deleted_at')
                ->orderBy('warehouses.id')
                ->value('product_warehouse.warehouse_id');
        }

        if (!$warehouseId) {
            $warehouseId = DB::table('warehouses')
                ->where('tenant_id', $invoice->tenant_id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');
        }

        if (!$warehouseId) {
            return;
        }

        $stockRow = DB::table('product_warehouse')
            ->where('product_id', $service->product_id)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if ($stockRow) {
            DB::table('product_warehouse')
                ->where('id', $stockRow->id)
                ->increment('stock', $quantity, ['updated_at' => now()]);
            return;
        }

        DB::table('product_warehouse')->insert([
            'product_id' => $service->product_id,
            'warehouse_id' => $warehouseId,
            'stock' => $quantity,
            'tenant_id' => $invoice->tenant_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function restoreInventoryStock(
        Product $product,
        Invoice $invoice,
        ReturnInvoice $return,
        $invoiceItem,
        float $quantity
    ): void {
        $saleMovements = InventoryMovement::query()
            ->withoutGlobalScope('branch')
            ->where('reference_type', Invoice::class)
            ->where('reference_id', $invoice->id)
            ->where('product_id', $product->id)
            ->where('movement_type', 'sale')
            ->where('quantity_delta', '<', 0)
            ->whereNotNull('warehouse_id')
            ->get()
            ->groupBy('warehouse_id')
            ->map(fn ($movements) => (float) $movements->sum(fn ($movement) => abs((float) $movement->quantity_delta)));

        $activeReturnIds = ReturnInvoice::query()
            ->where('invoice_id', $invoice->id)
            ->where(fn ($query) => $query->whereNull('workflow_status')
                ->orWhere('workflow_status', '!=', 'cancelled'))
            ->pluck('id');

        $returnedByWarehouse = $activeReturnIds->isEmpty()
            ? collect()
            : InventoryMovement::query()
                ->withoutGlobalScope('branch')
                ->where('reference_type', ReturnInvoice::class)
                ->whereIn('reference_id', $activeReturnIds)
                ->where('product_id', $product->id)
                ->where('quantity_delta', '>', 0)
                ->whereNotNull('warehouse_id')
                ->get()
                ->groupBy('warehouse_id')
                ->map(fn ($movements) => (float) $movements->sum('quantity_delta'));

        $allocations = [];
        $remaining = $quantity;
        foreach ($saleMovements as $warehouseId => $soldQuantity) {
            $availableToRestore = max(0, $soldQuantity - (float) $returnedByWarehouse->get($warehouseId, 0));
            $allocation = min($remaining, $availableToRestore);
            if ($allocation > 0) {
                $allocations[(int) $warehouseId] = $allocation;
                $remaining -= $allocation;
            }
            if ($remaining <= 0) {
                break;
            }
        }

        if ($remaining > 0) {
            $warehouses = DB::table('product_warehouse')
                ->join('warehouses', 'warehouses.id', '=', 'product_warehouse.warehouse_id')
                ->where('product_warehouse.product_id', $product->id)
                ->where('warehouses.tenant_id', $invoice->tenant_id)
                ->whereNull('warehouses.deleted_at')
                ->when($invoice->branch_id, fn ($query) => $query->where('warehouses.branch_id', $invoice->branch_id))
                ->orderBy('warehouses.id')
                ->pluck('product_warehouse.warehouse_id');

            if ($warehouses->isEmpty()) {
                $warehouses = DB::table('warehouses')
                    ->where('tenant_id', $invoice->tenant_id)
                    ->whereNull('deleted_at')
                    ->when($invoice->branch_id, fn ($query) => $query->where('branch_id', $invoice->branch_id))
                    ->orderBy('id')
                    ->pluck('id');
            }

            if ($warehouses->isNotEmpty()) {
                $warehouseId = (int) $warehouses->first();
                $allocations[$warehouseId] = ($allocations[$warehouseId] ?? 0) + $remaining;
                $remaining = 0;
            }
        }

        if ($remaining > 0) {
            $allocations[null] = $remaining;
        }

        foreach ($allocations as $warehouseId => $allocatedQuantity) {
            app(InventoryMovementService::class)->apply([
                'product_id' => $product->id,
                'branch_id' => $invoice->branch_id,
                'warehouse_id' => $warehouseId ?: null,
                'track_variant_stock' => false,
                'movement_type' => 'return',
                'quantity_delta' => $allocatedQuantity,
                'reference_type' => ReturnInvoice::class,
                'reference_id' => $return->id,
                'unit_cost' => (float) ($product->cost ?? 0),
                'notes' => "POS return for invoice {$invoice->invoice_number}; item {$invoiceItem->id}",
            ], (int) $invoice->tenant_id);
        }
    }

    public function invoiceReturnIndex(Request $request)
    {
        try {
            $filters          = $request->input('filters', []);
            $orderBy          = $request->input('orderBy', 'id');
            $orderByDirection = $request->input('orderByDirection', 'desc');
            $perPage          = $request->input('perPage', 10);
            $paginate         = $request->boolean('paginate', true);

            $query = ReturnInvoice::with(['invoice.customer']);

            if (!empty($filters['return_number'])) {
                $query->where('return_number', 'like', '%' . $filters['return_number'] . '%');
            }

            if (!empty($filters['invoice_number'])) {
                $query->whereHas('invoice', function ($q) use ($filters) {
                    $q->where('invoice_number', 'like', '%' . $filters['invoice_number'] . '%');
                });
            }

            if (!empty($filters['refund_method'])) {
                $query->where('refund_method', $filters['refund_method']);
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            $query->orderBy($orderBy, $orderByDirection);

            if ($paginate) {
                $returns = $query->paginate($perPage);

                return response()->json([
                    'data'   => ReturnInvoiceResource::collection($returns->items()),
                    'links'  => [
                        'first' => $returns->url(1),
                        'last'  => $returns->url($returns->lastPage()),
                        'prev'  => $returns->previousPageUrl(),
                        'next'  => $returns->nextPageUrl(),
                    ],
                    'meta'   => [
                        'current_page' => $returns->currentPage(),
                        'from'         => $returns->firstItem(),
                        'last_page'    => $returns->lastPage(),
                        'path'         => $returns->path(),
                        'per_page'     => $returns->perPage(),
                        'to'           => $returns->lastItem(),
                        'total'        => $returns->total(),
                    ],
                    'result'  => 'Success',
                    'message' => 'Return invoices fetched successfully',
                    'status'  => 200,
                ]);
            }

            $returns = $query->get();

            return response()->json([
                'data'    => ReturnInvoiceResource::collection($returns),
                'links'   => null,
                'meta'    => null,
                'result'  => 'Success',
                'message' => 'Return invoices fetched successfully',
                'status'  => 200,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'result'  => 'Error',
                'message' => $e->getMessage(),
                'status'  => 500,
            ], 500);
        }
    }
}
