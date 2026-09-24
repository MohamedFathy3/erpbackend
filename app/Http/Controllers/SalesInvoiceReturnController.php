<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSalesInvoiceReturnRequest;
use App\Http\Resources\SalesInvoiceReturnResource;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceReturn;
use App\Models\SalesInvoiceReturnItem;
use App\Models\CashierShift;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\LoyaltySetting;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Admin;
use App\Models\User;
use App\Services\WorkflowPostingService;
use App\Services\InventoryMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesInvoiceReturnController extends Controller
{

        // ============================================================
        // ✅ INDEX - جلب جميع المرتجعات
        // ============================================================
        public function index(Request $request)
        {
            try {
                $filters = $request->input('filters', []);
                $orderBy = $request->input('orderBy', 'id');
                $orderByDirection = $request->input('orderByDirection', 'desc');
                $perPage = $request->input('perPage', 10);
                $paginate = $request->boolean('paginate', true);

                $query = SalesInvoiceReturn::with([
                    'invoice.customer',
                    'items.product',
                    'treasury'
                ]);

                // =========================
                // FILTERS
                // =========================

                if (!empty($filters['return_number'])) {
                    $query->where('return_number', 'like', '%' . $filters['return_number'] . '%');
                }

                if (!empty($filters['invoice_number'])) {
                    $query->whereHas('invoice', function ($q) use ($filters) {
                        $q->where('invoice_number', 'like', '%' . $filters['invoice_number'] . '%');
                    });
                }

                if (!empty($filters['sales_invoice_id'])) {
                    $query->where('sales_invoice_id', $filters['sales_invoice_id']);
                }

                if (!empty($filters['customer_id'])) {
                    $query->whereHas('invoice', function ($q) use ($filters) {
                        $q->where('customer_id', $filters['customer_id']);
                    });
                }

                if (!empty($filters['min_total'])) {
                    $query->where('total_amount', '>=', $filters['min_total']);
                }

                if (!empty($filters['max_total'])) {
                    $query->where('total_amount', '<=', $filters['max_total']);
                }

                if (!empty($filters['date_from'])) {
                    $query->whereDate('created_at', '>=', $filters['date_from']);
                }

                if (!empty($filters['date_to'])) {
                    $query->whereDate('created_at', '<=', $filters['date_to']);
                }

                if (!empty($filters['product_id'])) {
                    $query->whereHas('items', function ($q) use ($filters) {
                        $q->where('product_id', $filters['product_id']);
                    });
                }

                // =========================
                // SORT
                // =========================
                $query->orderBy($orderBy, $orderByDirection);

                // =========================
                // PAGINATION
                // =========================
                if ($paginate) {
                    $returns = $query->paginate($perPage);

                    return response()->json([
                        'data' => SalesInvoiceReturnResource::collection($returns->items()),
                        'links' => [
                            'first' => $returns->url(1),
                            'last' => $returns->url($returns->lastPage()),
                            'prev' => $returns->previousPageUrl(),
                            'next' => $returns->nextPageUrl(),
                        ],
                        'meta' => [
                            'current_page' => $returns->currentPage(),
                            'from' => $returns->firstItem(),
                            'last_page' => $returns->lastPage(),
                            'path' => $returns->path(),
                            'per_page' => $returns->perPage(),
                            'to' => $returns->lastItem(),
                            'total' => $returns->total(),
                        ],
                        'result' => 'Success',
                        'message' => 'Sales returns fetched successfully',
                        'status' => 200,
                    ]);
                }

                $returns = $query->get();

                return response()->json([
                    'data' => SalesInvoiceReturnResource::collection($returns),
                    'links' => null,
                    'meta' => null,
                    'result' => 'Success',
                    'message' => 'Sales returns fetched successfully',
                    'status' => 200,
                ]);

            } catch (\Exception $e) {
                return response()->json([
                    'result' => 'Error',
                    'message' => $e->getMessage(),
                    'status' => 500,
                ], 500);
            }
        }

        // ============================================================
        // ✅ STORE DIRECT RETURN - مرتجع مباشر (بدون فاتورة)
        // ============================================================

    public function storeDirectReturn(
        Request $request,
        InventoryMovementService $inventory
    ) {
        DB::beginTransaction();

        try {
            $user = auth()->user();

            // ============================================================
            // تحديد cashier_id و treasury_id
            // ============================================================

            $cashierId = null;
            $treasuryId = null;

            if ($user instanceof Employee) {
                $cashierId = $user->id;
                $treasuryId = $user->treasury_id;

                if (!$treasuryId) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'الموظف ليس لديه خزينة مخصصة'
                    ], 400);
                }
            } elseif ($user instanceof Admin) {
                $cashierId = null;

                $mainTreasury = Treasury::where('is_main', true)->first();
                $treasuryId = $mainTreasury?->id;

                if (!$treasuryId) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'لا توجد خزينة رئيسية'
                    ], 400);
                }
            } else {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'المستخدم غير صالح لتنفيذ المرتجع'
                ], 403);
            }

            // ============================================================
            // Validation
            // ============================================================

            $validated = $request->validate([
                'supplier_id' => 'nullable|exists:suppliers,id',
                'sales_invoice_id' => 'nullable|exists:sales_invoices,id',

                'return_method' => 'required|in:cash,card,wallet,bank',

                'note' => 'nullable|string|max:500',

                'branch_id' => 'nullable|exists:branches,id',

                'warehouse_id' => 'required|exists:warehouses,id',

                'shift_id' => 'nullable|exists:cashier_shifts,id',

                'items' => 'required|array|min:1',

                'items.*.product_id' => 'required|exists:products,id',

                'items.*.product_unit_id' =>
                    'nullable|exists:product_units,id',

                'items.*.color_id' =>
                    'nullable|exists:colors,id',

                'items.*.size_id' =>
                    'nullable|exists:sizes,id',

                'items.*.size' =>
                    'nullable|string|max:100',

                'items.*.quantity' =>
                    'required|numeric|min:0.0001',

                'items.*.price' =>
                    'required|numeric|min:0',

                'items.*.reason' =>
                    'required|in:defective,wrong_item,damaged,customer_change,other',

                'items.*.discount' =>
                    'nullable|numeric|min:0|max:100',

                'items.*.tax' =>
                    'nullable|numeric|min:0|max:100',
            ]);

            // ============================================================
            // Return Number
            // ============================================================

            $returnNumber =
                'DR-' .
                now()->format('Ymd') .
                '-' .
                str_pad(
                    SalesInvoiceReturn::count() + 1,
                    4,
                    '0',
                    STR_PAD_LEFT
                );

            // ============================================================
            // حساب الإجمالي
            // ============================================================

            $totalReturn = collect($validated['items'])->sum(function ($item) {

                $itemTotal =
                    (float) $item['quantity'] *
                    (float) $item['price'];

                $discount = isset($item['discount'])
                    ? ($itemTotal * (float) $item['discount']) / 100
                    : 0;

                $tax = isset($item['tax'])
                    ? (($itemTotal - $discount) * (float) $item['tax']) / 100
                    : 0;

                return $itemTotal - $discount + $tax;
            });

            // ============================================================
            // تحديد Shift
            // ============================================================

            $shiftId = $request->shift_id;

            if (!$shiftId) {

                $shift = CashierShift::where('status', 'open')
                    ->where(function ($q) use ($user) {

                        if ($user instanceof Admin) {
                            $q->where('admin_id', $user->id);
                        }

                        if ($user instanceof Employee) {
                            $q->where('employee_id', $user->id);
                        }
                    })
                    ->latest('opened_at')
                    ->first();

                if ($shift) {
                    $shiftId = $shift->id;
                }
            }

            // ============================================================
            // إنشاء المرتجع
            // ============================================================

            $return = SalesInvoiceReturn::create([
                'return_number' => $returnNumber,

                'sales_invoice_id' =>
                    $validated['sales_invoice_id'] ?? null,

                'return_method' =>
                    $validated['return_method'],

                'total_amount' =>
                    $totalReturn,

                'note' =>
                    $validated['note'] ?? null,

                'supplier_id' =>
                    $validated['supplier_id'] ?? null,

                'branch_id' =>
                    $validated['branch_id'] ?? null,

                'warehouse_id' =>
                    $validated['warehouse_id'],

                'shift_id' =>
                    $shiftId,

                'cashier_id' =>
                    $cashierId,

                'treasury_id' =>
                    $treasuryId,

                'created_by' =>
                    $user instanceof User
                        ? $user->id
                        : null,

                'is_direct' => true,
            ]);

            // ============================================================
            // طرق الدفع
            // ============================================================

            $cashReturn = 0;
            $cardReturn = 0;
            $walletReturn = 0;

            $returnMethod = $validated['return_method'];

            if (in_array($returnMethod, [
                'cash',
                'نقدي',
                'نقداً',
                'نقدا'
            ])) {

                $cashReturn = $totalReturn;

            } elseif (in_array($returnMethod, [
                'card',
                'بطاقة',
                'بطاقه'
            ])) {

                $cardReturn = $totalReturn;

            } elseif (in_array($returnMethod, [
                'wallet',
                'محفظة',
                'محفظه',
                'رصيد'
            ])) {

                $walletReturn = $totalReturn;
            }

            // ============================================================
            // المنتجات + حركة المخزون
            // ============================================================

            foreach ($validated['items'] as $item) {

                $lineTotal =
                    (float) $item['quantity'] *
                    (float) $item['price'];

                SalesInvoiceReturnItem::create([
                    'sales_invoice_return_id' =>
                        $return->id,

                    'product_id' =>
                        $item['product_id'],

                    'product_unit_id' =>
                        $item['product_unit_id'] ?? null,

                    'color_id' =>
                        $item['color_id'] ?? null,

                    'size' =>
                        $item['size'] ?? null,

                    'quantity' =>
                        $item['quantity'],

                    'price' =>
                        $item['price'],

                    'total' =>
                        $lineTotal,

                    'reason' =>
                        $item['reason'],

                    'discount' =>
                        $item['discount'] ?? 0,

                    'tax' =>
                        $item['tax'] ?? 0,
                ]);

                // ========================================================
                // زيادة المخزون عن طريق InventoryMovementService
                // ========================================================

                $inventory->apply([
                    'product_id' =>
                        $item['product_id'],

                    'product_unit_id' =>
                        $item['product_unit_id'] ?? null,

                    'size_id' =>
                        $item['size_id'] ?? null,

                    'color_id' =>
                        $item['color_id'] ?? null,

                    'branch_id' =>
                        $validated['branch_id'] ?? null,

                    'warehouse_id' =>
                        $validated['warehouse_id'],

                    'movement_type' =>
                        'sales_return',

                    'quantity_delta' =>
                        (float) $item['quantity'],

                    'reference_type' =>
                        SalesInvoiceReturn::class,

                    'reference_id' =>
                        $return->id,

                    'notes' =>
                        "Sales return {$return->return_number}",
                ]);
            }

            // ============================================================
            // خصم المبلغ من الخزينة
            // ============================================================

            if ($cashReturn > 0 && $treasuryId) {

                $treasury = Treasury::lockForUpdate()
                    ->find($treasuryId);

                if (!$treasury) {
                    throw new \RuntimeException(
                        'الخزينة غير موجودة'
                    );
                }

                if ((float) $treasury->balance < $cashReturn) {
                    throw new \RuntimeException(
                        'رصيد الخزينة غير كافٍ لتنفيذ المرتجع'
                    );
                }

                $treasury->decrement(
                    'balance',
                    $cashReturn
                );

                TreasuryTransaction::create([
                    'treasury_id' =>
                        $treasuryId,

                    'reference_type' =>
                        SalesInvoiceReturn::class,

                    'reference_id' =>
                        $return->id,

                    'type' =>
                        'out',

                    'amount' =>
                        $cashReturn,

                    'description' =>
                        "مرتجع منتجات رقم {$return->return_number}",

                    'created_by' =>
                        $user instanceof User
                            ? $user->id
                            : null,

                    'created_at' =>
                        now(),
                ]);
            }

            // ============================================================
            // تحديث الوردية
            // ============================================================

            if ($shiftId) {

                $shift = CashierShift::lockForUpdate()
                    ->find($shiftId);

                if ($shift) {

                    $shift->update([
                        'returns_amount' =>
                            ($shift->returns_amount ?? 0)
                            + $totalReturn,

                        'cash_sales' =>
                            ($shift->cash_sales ?? 0)
                            - $cashReturn,

                        'card_sales' =>
                            ($shift->card_sales ?? 0)
                            - $cardReturn,

                        'wallet_sales' =>
                            ($shift->wallet_sales ?? 0)
                            - $walletReturn,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'data' =>
                    $return->load(
                        'items.product',
                        'treasury'
                    ),

                'result' =>
                    'Success',

                'message' =>
                    'Direct return created successfully',

                'status' =>
                    200,
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error(
                'Direct Return Error',
                [
                    'message' =>
                        $e->getMessage(),

                    'trace' =>
                        $e->getTraceAsString(),

                    'request' =>
                        $request->all(),
                ]
            );

            return response()->json([
                'result' =>
                    'Error',

                'message' =>
                    $e->getMessage(),

                'status' =>
                    500,
            ], 500);
        }
    }
        // ============================================================
        // ✅ SHOW - عرض مرتجع واحد
        // ============================================================
        public function show($id)
        {
            try {
                $return = SalesInvoiceReturn::with([
                    'invoice.customer',
                    'items.product',
                    'treasury'
                ])->findOrFail($id);

                return response()->json([
                    'data' => new SalesInvoiceReturnResource($return),
                    'result' => 'Success',
                    'message' => 'Sales return fetched successfully',
                    'status' => 200,
                ]);

            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                return response()->json([
                    'result' => 'Error',
                    'message' => 'Sales return not found',
                    'status' => 404,
                ], 404);

            } catch (\Exception $e) {
                return response()->json([
                    'result' => 'Error',
                    'message' => $e->getMessage(),
                    'status' => 500,
                ], 500);
            }
        }

        // ============================================================
        // ✅ STORE RETURN - مرتجع من فاتورة موجودة
        // ============================================================

    public function storeReturn(
        StoreSalesInvoiceReturnRequest $request,
        WorkflowPostingService $posting,
        InventoryMovementService $inventory
    ) {
        try {
            $return = DB::transaction(function () use ($request, $posting, $inventory) {
                $invoice = SalesInvoice::with(['items', 'customer'])
                    ->lockForUpdate()
                    ->findOrFail($request->sales_invoice_id);

                $warehouseId = $invoice->warehouse_id;
                if (!$warehouseId) throw new \RuntimeException('لا يمكن تنفيذ المرتجع: الفاتورة لا تحتوي على مخزن.');

                $returnItems = [];
                $requestedByInvoiceItem = [];
                foreach ($request->items as $input) {
                    $productId = (int) $input['product_id'];
                    $candidates = $invoice->items->where('product_id', $productId)->values();
                    foreach (['product_unit_id', 'color_id', 'size_id', 'product_variant_id'] as $field) {
                        if (array_key_exists($field, $input)) {
                            $candidates = $candidates->filter(fn ($line) => (int) ($line->{$field} ?? 0) === (int) ($input[$field] ?? 0))->values();
                        }
                    }
                    if ($candidates->count() !== 1) {
                        throw new \RuntimeException($candidates->isEmpty()
                            ? "المنتج رقم {$productId} أو مواصفته غير موجودة في الفاتورة الأصلية."
                            : "المنتج رقم {$productId} له أكثر من وحدة/لون/مقاس؛ أرسل مواصفات الصنف كاملة.");
                    }
                    $invoiceItem = $candidates->first();
                    $requestedByInvoiceItem[$invoiceItem->id] = ($requestedByInvoiceItem[$invoiceItem->id] ?? 0) + (float) $input['quantity'];

                    $returnedQuery = SalesInvoiceReturnItem::query()
                        ->where('product_id', $productId)
                        ->where('product_unit_id', $invoiceItem->product_unit_id)
                        ->where('color_id', $invoiceItem->color_id)
                        ->where('size_id', $invoiceItem->size_id)
                        ->where('product_variant_id', $invoiceItem->product_variant_id)
                        ->whereHas('return', function ($q) use ($invoice) {
                            $q->where('sales_invoice_id', $invoice->id)
                                ->where(function ($status) {
                                    $status->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'cancelled');
                                });
                        });
                    $alreadyReturned = (float) $returnedQuery->sum('quantity');
                    $available = max(0, (float) $invoiceItem->quantity - $alreadyReturned);
                    $requested = $requestedByInvoiceItem[$invoiceItem->id];
                    if ($requested > $available) {
                        throw new \RuntimeException("الكمية المطلوبة لإرجاع المنتج {$productId} هي {$requested} والمتاح المتبقي من الفاتورة {$available} فقط.");
                    }

                    // The original invoice controls price; never trust a client-supplied refund price.
                    $price = (float) $invoiceItem->price;
                    $returnItems[] = [
                        'invoice_item' => $invoiceItem,
                        'product_id' => $productId,
                        'product_unit_id' => $invoiceItem->product_unit_id,
                        'color_id' => $invoiceItem->color_id,
                        'size_id' => $invoiceItem->size_id,
                        'product_variant_id' => $invoiceItem->product_variant_id,
                        'quantity' => (float) $input['quantity'],
                        'price' => $price,
                        'total' => round((float) $input['quantity'] * $price, 2),
                        'reason' => $input['reason'],
                    ];
                }

                $totalReturn = round(array_sum(array_column($returnItems, 'total')), 2);
                $returnMethod = (string) $request->return_method;
                $isCashRefund = in_array($returnMethod, ['cash', 'نقدي', 'نقداً', 'نقدا'], true);
                if ($isCashRefund && $totalReturn > (float) ($invoice->paid_amount ?? 0)) {
                    throw new \RuntimeException('قيمة رد النقد تتجاوز المبلغ المحصل من الفاتورة. اختر ردًا على حساب العميل أو قلل المبلغ.');
                }

                $treasuryId = $isCashRefund ? ($request->treasury_id ?: $invoice->treasury_id) : null;
                if ($isCashRefund && !$treasuryId) throw new \RuntimeException('اختر خزينة لرد المبلغ النقدي.');
                $returnNumber = 'SR-' . now()->format('YmdHis') . '-' . random_int(100, 999);
                $return = SalesInvoiceReturn::create([
                    'sales_invoice_id' => $invoice->id,
                    'return_number' => $returnNumber,
                    'return_method' => $returnMethod,
                    'total_amount' => $totalReturn,
                    'note' => $request->note,
                    'treasury_id' => $treasuryId,
                    'branch_id' => $invoice->branch_id,
                    'warehouse_id' => $warehouseId,
                    'shift_id' => $request->shift_id,
                    'cashier_id' => auth()->user() instanceof Employee ? auth()->id() : null,
                    'created_by' => auth()->user() instanceof User ? auth()->id() : null,
                    'is_direct' => false,
                ]);

                foreach ($returnItems as $item) {
                    SalesInvoiceReturnItem::create([
                        'sales_invoice_return_id' => $return->id,
                        'product_id' => $item['product_id'],
                        'product_unit_id' => $item['product_unit_id'],
                        'color_id' => $item['color_id'],
                        'size_id' => $item['size_id'],
                        'product_variant_id' => $item['product_variant_id'],
                        'size' => $item['size_id'] ? (string) $item['size_id'] : null,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'total' => $item['total'],
                        'reason' => $item['reason'],
                    ]);
                    $inventory->apply([
                        'product_id' => $item['product_id'],
                        'product_unit_id' => $item['product_unit_id'],
                        'size_id' => $item['size_id'],
                        'color_id' => $item['color_id'],
                        'branch_id' => $invoice->branch_id,
                        'warehouse_id' => $warehouseId,
                        'movement_type' => 'sales_return',
                        'quantity_delta' => $item['quantity'],
                        'reference_type' => SalesInvoiceReturn::class,
                        'reference_id' => $return->id,
                        'notes' => "Sales return {$returnNumber} from invoice {$invoice->invoice_number}",
                    ]);
                }

                if ($isCashRefund && $totalReturn > 0) {
                    $treasury = Treasury::query()->lockForUpdate()->findOrFail($treasuryId);
                    if ((float) $treasury->balance < $totalReturn) throw new \RuntimeException('رصيد الخزينة غير كافٍ لتنفيذ المرتجع.');
                    $treasury->decrement('balance', $totalReturn);
                    TreasuryTransaction::create([
                        'treasury_id' => $treasury->id,
                        'reference_type' => SalesInvoiceReturn::class,
                        'reference_id' => $return->id,
                        'type' => 'out',
                        'amount' => $totalReturn,
                        'description' => "رد نقدي لمرتجع المبيعات {$returnNumber}",
                        'created_by' => auth()->user() instanceof User ? auth()->id() : null,
                    ]);
                    $invoice->update(['paid_amount' => max(0, (float) $invoice->paid_amount - $totalReturn)]);
                }

                if ($invoice->customer_id) {
                    $this->deductLoyaltyPoints($invoice->customer_id, $totalReturn);
                    $this->deductTotalPurchases($invoice->customer_id, $totalReturn);
                    $this->updateLastPaidAmount($invoice->customer_id, $totalReturn);
                }

                $journal = $posting->postReturn($return->load('invoice.customer', 'items.product'), 'sales_return', $totalReturn, $treasuryId);
                $return->update([
                    'posting_journal_entry_id' => $journal?->id,
                    'workflow_status' => $journal ? 'posted' : 'pending_finance',
                ]);

                return $return->load('items.product', 'invoice.customer', 'treasury');
            });

            return response()->json(['data' => $return, 'result' => 'Success', 'message' => 'Sales return created successfully', 'status' => 200]);
        } catch (\RuntimeException $e) {
            return response()->json(['result' => 'Error', 'message' => $e->getMessage(), 'status' => 422], 422);
        } catch (\Throwable $e) {
            Log::error('Store Sales Return Error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'request' => $request->all()]);
            return response()->json(['result' => 'Error', 'message' => $e->getMessage(), 'status' => 500], 500);
        }
    }

    public function cancel(
        $id,
        WorkflowPostingService $posting,
        InventoryMovementService $inventory
    ) {
        DB::beginTransaction();

        try {

            // ============================================================
            // جلب المرتجع
            // ============================================================

            $return = SalesInvoiceReturn::with([
                'items',
                'invoice'
            ])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($return->workflow_status === 'cancelled') {

                throw new \RuntimeException(
                    'مرتجع المبيعات ملغى بالفعل'
                );
            }

            // ============================================================
            // تحديد المخزن
            // ============================================================

            $warehouseId =
                $return->warehouse_id
                ?? $return->invoice?->warehouse_id;

            if (!$warehouseId) {

                throw new \RuntimeException(
                    'لا يمكن إلغاء المرتجع: لم يتم تحديد المخزن'
                );
            }

            // ============================================================
            // تحديد الفرع
            // ============================================================

            $branchId =
                $return->branch_id
                ?? $return->invoice?->branch_id
                ?? null;

            // ============================================================
            // عكس حركة المخزون
            // ============================================================

            foreach ($return->items as $item) {

                $inventory->apply([
                    'product_id' =>
                        $item->product_id,

                    'product_unit_id' =>
                        $item->product_unit_id,

                    'size_id' =>
                        $item->size_id ?? null,

                    'color_id' =>
                        $item->color_id,

                    'branch_id' =>
                        $branchId,

                    'warehouse_id' =>
                        $warehouseId,

                    'movement_type' =>
                        'sales_return_cancel',

                    'quantity_delta' =>
                        -((float) $item->quantity),

                    'reference_type' =>
                        SalesInvoiceReturn::class,

                    'reference_id' =>
                        $return->id,

                    'notes' =>
                        "Cancel sales return {$return->return_number}",
                ]);
            }

            // ============================================================
            // عكس القيد المالي
            // ============================================================

            $posting->reverseInvoice(
                $return,
                'sales_return'
            );

            // ============================================================
            // عكس مبلغ الخزينة
            // ============================================================

            $refund = TreasuryTransaction::where(
                'reference_type',
                SalesInvoiceReturn::class
            )
                ->where(
                    'reference_id',
                    $return->id
                )
                ->where(
                    'type',
                    'out'
                )
                ->latest()
                ->first();

            if ($refund) {

                Treasury::whereKey(
                    $refund->treasury_id
                )->increment(
                    'balance',
                    $refund->amount
                );

                TreasuryTransaction::create([
                    'treasury_id' =>
                        $refund->treasury_id,

                    'reference_type' =>
                        SalesInvoiceReturn::class,

                    'reference_id' =>
                        $return->id,

                    'type' =>
                        'in',

                    'amount' =>
                        $refund->amount,

                    'description' =>
                        "عكس إلغاء مرتجع مبيعات رقم {$return->return_number}",

                    'created_by' =>
                        auth()->user() instanceof User
                            ? auth()->user()->id
                            : null,

                    'created_at' =>
                        now(),
                ]);
            }

            // ============================================================
            // عكس الولاء
            // ============================================================

            $amount =
                (float) (
                    $return->total_amount
                    ?? $return->items->sum('total')
                );

            if ($return->invoice?->customer_id) {

                $this->deductLoyaltyPoints(
                    $return->invoice->customer_id,
                    -$amount
                );

                $this->deductTotalPurchases(
                    $return->invoice->customer_id,
                    -$amount
                );

                $this->updateLastPaidAmount(
                    $return->invoice->customer_id,
                    -$amount
                );
            }

            // ============================================================
            // تحديث الحالة
            // ============================================================

            $return->update([
                'workflow_status' =>
                    'cancelled',
            ]);

            DB::commit();

            return response()->json([
                'status' =>
                    true,

                'message' =>
                    'تم إلغاء المرتجع وعكس الأثر المالي والمخزني',
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error(
                'Cancel Sales Return Error',
                [
                    'message' =>
                        $e->getMessage(),

                    'trace' =>
                        $e->getTraceAsString(),

                    'return_id' =>
                        $id,
                ]
            );

            return response()->json([
                'status' =>
                    false,

                'message' =>
                    $e->getMessage(),
            ], 422);
        }
    }

        // ============================================================
        // ✅ دالة خصم نقاط الولاء عند المرتجع
        // ============================================================
        private function deductLoyaltyPoints($customerId, $returnAmount)
        {
            Log::info('⭐ ========== LOYALTY POINTS DEDUCT START ==========');
            Log::info('📊 Customer ID: ' . $customerId);
            Log::info('💰 Return Amount: ' . $returnAmount);

            try {
                $loyaltySetting = LoyaltySetting::first();
                
                $pointsPerCurrency = (float) ($loyaltySetting?->points ?? 0);
                if (!$loyaltySetting || $pointsPerCurrency <= 0) {
                    return;
                }

                $customer = Customer::find($customerId);
                
                if (!$customer) {
                    Log::error('❌ Customer not found for ID: ' . $customerId);
                    return;
                }

                $currentPoints = $customer->point ?? 0;
                $deductedPoints = floor(abs($returnAmount) / $pointsPerCurrency);
                $newPoints = max(0, $currentPoints - $deductedPoints);

                Log::info('🧮 Points Deduction:', [
                    'current_points' => $currentPoints,
                    'deducted_points' => $deductedPoints,
                    'new_points' => $newPoints
                ]);

                $customer->point = $newPoints;
                $customer->save();

                Log::info('✅ Points deducted!', [
                    'customer_id' => $customer->id,
                    'new_points' => $customer->point
                ]);

                $this->updateCustomerLevel($customer, $loyaltySetting);

            } catch (\Exception $e) {
                Log::error('❌ Error deducting loyalty points: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }

            Log::info('⭐ ========== LOYALTY POINTS DEDUCT END ==========');
        }

        // ============================================================
        // ✅ دالة خصم إجمالي المشتريات عند المرتجع
        // ============================================================
        private function deductTotalPurchases($customerId, $returnAmount)
        {
            Log::info('📊 ========== TOTAL PURCHASES DEDUCT START ==========');
            Log::info('📊 Customer ID: ' . $customerId);
            Log::info('💰 Return Amount: ' . $returnAmount);

            try {
                $customer = Customer::find($customerId);
                
                if (!$customer) {
                    Log::error('❌ Customer not found for ID: ' . $customerId);
                    return;
                }

                $oldTotalPurchases = $customer->total_purchases ?? 0;
                $newTotalPurchases = max(0, $oldTotalPurchases - $returnAmount);

                $customer->total_purchases = $newTotalPurchases;
                $customer->save();

                Log::info('✅ Total purchases deducted!', [
                    'customer_id' => $customer->id,
                    'old_total_purchases' => $oldTotalPurchases,
                    'new_total_purchases' => $customer->total_purchases
                ]);

            } catch (\Exception $e) {
                Log::error('❌ Error deducting total purchases: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }

            Log::info('📊 ========== TOTAL PURCHASES DEDUCT END ==========');
        }

        // ============================================================
        // ✅ دالة تحديث آخر مبلغ مدفوع
        // ============================================================
        private function updateLastPaidAmount($customerId, $returnAmount)
        {
            Log::info('💳 ========== LAST PAID AMOUNT UPDATE START ==========');
            Log::info('📊 Customer ID: ' . $customerId);
            Log::info('💰 Return Amount: ' . $returnAmount);

            try {
                $customer = Customer::find($customerId);
                
                if (!$customer) {
                    Log::error('❌ Customer not found for ID: ' . $customerId);
                    return;
                }

                $oldLastPaidAmount = $customer->last_paid_amount ?? 0;
                $newLastPaidAmount = max(0, $oldLastPaidAmount - $returnAmount);

                $customer->last_paid_amount = $newLastPaidAmount;
                $customer->save();

                Log::info('✅ Last paid amount updated!', [
                    'customer_id' => $customer->id,
                    'old_last_paid_amount' => $oldLastPaidAmount,
                    'new_last_paid_amount' => $customer->last_paid_amount
                ]);

            } catch (\Exception $e) {
                Log::error('❌ Error updating last paid amount: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }

            Log::info('💳 ========== LAST PAID AMOUNT UPDATE END ==========');
        }

        // ============================================================
        // ✅ دالة تحديث مستوى العميل
        // ============================================================
        private function updateCustomerLevel($customer, $loyaltySetting)
        {
            $points = $customer->point ?? 0;
            $level = 'bronze';

            if ($points >= $loyaltySetting->platinum) {
                $level = 'platinum';
            } elseif ($points >= $loyaltySetting->gold) {
                $level = 'gold';
            } elseif ($points >= $loyaltySetting->silver) {
                $level = 'silver';
            }

            $customer->level = $level;
            $customer->save();

            Log::info('🏆 Customer level updated:', [
                'customer_id' => $customer->id,
                'level' => $level,
                'points' => $points
            ]);

            return $level;
        }

        
    }
