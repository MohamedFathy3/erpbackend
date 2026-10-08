<?php

namespace App\Http\Controllers;

use App\Http\Resources\InvoiceResource;
use App\Models\Admin;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\LoyaltySetting;
use App\Models\Product;
use App\Models\SalesRepresentative;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\PosAccountingPostingService;
use App\Services\PosInvoicePricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

class InvoiceController extends Controller
{
    public function cashierAccess(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'password' => ['required', 'string'],
        ]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        abort_unless($employee->is_active !== false && Hash::check($data['password'], (string) $employee->password), 403, 'كلمة مرور الكاشير غير صحيحة.');
        $invoices = Invoice::with(['customer', 'branch', 'cashier', 'treasury', 'salesRepresentative', 'shift'])
            ->where('cashier_id', $employee->id)
            ->latest('id')->limit(200)->get();
        return response()->json(['result' => 'Success', 'data' => InvoiceResource::collection($invoices)]);
    }

    public function salesRepAccess(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $representative = SalesRepresentative::query()->where('email', $data['email'])->first();
        abort_unless($representative && $representative->active && $representative->password && Hash::check($data['password'], $representative->password), 403, 'بيانات مندوب المبيعات غير صحيحة.');
        $invoices = Invoice::with(['customer', 'branch', 'treasury', 'salesRepresentative', 'shift'])
            ->where('sales_representative_id', $representative->id)
            ->whereDate('created_at', now()->toDateString())->latest('id')->limit(200)->get();
        return response()->json(['result' => 'Success', 'data' => [
            'employee' => ['id' => $representative->employee_id, 'name' => $representative->name, 'email' => $representative->email, 'representative_id' => $representative->id],
            'representative' => $representative->only(['id', 'name', 'email', 'employee_id', 'commission_rate']),
            'representatives' => SalesRepresentative::query()->where('active', true)->get(['id', 'name', 'email', 'employee_id']),
            'invoices' => InvoiceResource::collection($invoices),
        ]]);
    }

    public function invoiceIndex(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $orderBy = $request->input('orderBy', 'id');
            $orderByDirection = $request->input('orderByDirection', 'desc');
            $perPage = $request->input('perPage', 10);
            $paginate = $request->boolean('paginate', true);

            $query = Invoice::with([
                'customer',
                'branch',
                'cashier',
                'treasury',
                'salesRepresentative',
                'shift',
                'returns'
            ]);

            // =========================
            // FILTERS
            // =========================

            if (!empty($filters['invoice_number'])) {
                $query->where('invoice_number', 'like', '%' . $filters['invoice_number'] . '%');
            }

            if (!empty($filters['customer_id'])) {
                $query->where('customer_id', $filters['customer_id']);
            }

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['cashier_id'])) {
                $query->where('cashier_id', $filters['cashier_id']);
            }

            if (!empty($filters['treasury_id'])) {
                $query->where('treasury_id', $filters['treasury_id']);
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            // =========================
            // SORT
            // =========================
            $query->orderBy($orderBy, $orderByDirection);

            // =========================
            // PAGINATION MODE
            // =========================
            if ($paginate) {
                $invoices = $query->paginate($perPage);

                return response()->json([
                    'data' => InvoiceResource::collection($invoices->items()),
                    'links' => [
                        'first' => $invoices->url(1),
                        'last' => $invoices->url($invoices->lastPage()),
                        'prev' => $invoices->previousPageUrl(),
                        'next' => $invoices->nextPageUrl(),
                    ],
                    'meta' => [
                        'current_page' => $invoices->currentPage(),
                        'from' => $invoices->firstItem(),
                        'last_page' => $invoices->lastPage(),
                        'path' => $invoices->path(),
                        'per_page' => $invoices->perPage(),
                        'to' => $invoices->lastItem(),
                        'total' => $invoices->total(),
                    ],
                    'result' => 'Success',
                    'message' => 'Invoices fetched successfully',
                    'status' => 200,
                ]);
            }

            // =========================
            // NON PAGINATED MODE
            // =========================
            $invoices = $query->get();

            return response()->json([
                'data' => InvoiceResource::collection($invoices),
                'links' => null,
                'meta' => null,
                'result' => 'Success',
                'message' => 'Invoices fetched successfully',
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

public function store(Request $request)
{
    $request->validate([
        'customer_id' => 'nullable|exists:customers,id',
        'items' => 'required|array|min:1',
        'items.*.product_id' => 'required|exists:products,id',
        'items.*.quantity' => 'required|numeric|gt:0',
        'items.*.price' => 'required|numeric|min:0',
        'payments' => 'nullable|array',
        'payments.*.method' => 'required|in:cash,card,wallet',
        'payments.*.amount' => 'required|numeric|gt:0',
        'discount_percentage' => 'nullable|numeric|min:0|max:100',
        'extra_charge' => 'nullable|numeric|min:0',
        'is_complimentary' => 'sometimes|boolean',
        'sales_representative_id' => 'nullable|integer|exists:sales_representatives,id',
        'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
        'items.*.discount_amount' => 'nullable|numeric|min:0',
        'items.*.vehicle_size' => 'nullable|in:small,large',
        'items.*.meter_quantity' => 'nullable|numeric|min:0.001',
        'items.*.product_unit_id' => 'nullable|integer|exists:product_units,id',
        'items.*.color_id' => 'nullable|integer|exists:colors,id',
    ]);

    // POS invoices without a selected customer are posted to the default
    // customer (ID 1) instead of leaving the customer link null.
    if (!$request->filled('customer_id')) {
        if (!Customer::query()->whereKey(1)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'العميل الافتراضي رقم 1 غير موجود في مساحة العمل الحالية.',
            ], 400);
        }
        $request->merge(['customer_id' => 1]);
    }

    $isComplimentary = $request->boolean('is_complimentary');
    $user = $request->user();
    $hasDiscount = $isComplimentary || (float) $request->input('discount_percentage', 0) > 0
        || collect($request->input('items', []))->contains(fn ($item) =>
            (float) ($item['discount_percentage'] ?? 0) > 0 || (float) ($item['discount_amount'] ?? 0) > 0
        );
    if ($hasDiscount && !$this->mayApplyPosDiscount($user)) {
        return response()->json([
            'message' => 'لا تملك صلاحية تطبيق خصم نقطة البيع.',
            'code' => 'pos_discount_permission_required',
        ], 403);
    }
    if ($isComplimentary) {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);
        $customer = Customer::query()->find($request->integer('customer_id'));
        if (!$customer || trim((string) $customer->name) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'customer_id' => 'فاتورة المجاملات تتطلب اختيار عميل مسجل في مساحة العمل الحالية.',
            ]);
        }
    }

    DB::beginTransaction();
    
    try {
        $user = auth()->user();

        // ✅ جلب وردية المستخدم الحالي
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

        if (!$shift && !($user instanceof Admin)) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'لا توجد وردية مفتوحة لك. يرجى فتح وردية أولاً.'
            ], 400);
        }

        $cashierId = $shift?->employee_id;
        $employee = $cashierId ? Employee::with('treasury')->find($cashierId) : null;

        if ($user instanceof Admin) {
            $treasury = Treasury::query()->where('is_main', true)->first();
            if (!$treasury) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'لا توجد خزنة رئيسية مفعلة لمساحة العمل الحالية.'
                ], 400);
            }
            $treasuryId = $treasury->id;
        } else {
            $treasury = $employee?->treasury;
            $treasuryId = $employee?->treasury_id;
            if (!$treasuryId || !$treasury) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'الموظف المرتبط بالوردية ليس لديه خزينة مخصصة.'
                ], 400);
            }
        }
        $branchId = (int) ($employee?->branch_id ?? $request->input('branch_id') ?? $treasury->branch_id ?? 0);
        $tenantId = $user?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);

        // ============================================================
        // ✅ حساب المجاميع
        // ============================================================
        $pricing = app(PosInvoicePricingService::class)->calculate(
            $request->input('items', []),
            (float) ($request->input('discount_percentage') ?? 0),
            $isComplimentary,
            (float) ($request->input('extra_charge') ?? 0),
        );
        $total = $pricing['gross_total'];
        $itemDiscountTotal = $pricing['item_discount_total'];
        $afterItemDiscounts = $pricing['after_item_discounts'];
        $invoiceDiscountPercentage = $pricing['invoice_discount_percentage'];
        $invoiceDiscountAmount = $pricing['invoice_discount_amount'];
        $totalDiscountAmount = $pricing['total_discount_amount'];
        $effectiveDiscountPercentage = $pricing['effective_discount_percentage'];

        $invoiceNumber = 'INV-' . now()->format('Ymd') . '-' . rand(1000, 9999);
        $netTotal = $pricing['net_total'];
        $submittedPayments = $isComplimentary ? collect() : collect($request->input('payments', []));
        $cashReceived = (float) $submittedPayments->where('method', 'cash')->sum('amount');
        $nonCashPaid = $submittedPayments
            ->whereIn('method', ['card', 'wallet'])
            ->sum('amount');
        if ($nonCashPaid > $netTotal) {
            throw \Illuminate\Validation\ValidationException::withMessages(['payments' => 'إجمالي المدفوعات الإلكترونية أكبر من صافي الفاتورة.']);
        }

        // Cash handed over may exceed the amount due. Record only the amount
        // actually owed; the POS receipt displays the tendered amount/change.
        $cashCapacity = max(0, $netTotal - $nonCashPaid);
        $payments = [];
        foreach ($submittedPayments as $payment) {
            $amount = (float) $payment['amount'];
            if ($payment['method'] === 'cash') {
                $amount = min($amount, $cashCapacity);
                $cashCapacity -= $amount;
            }
            if ($amount > 0) {
                $payments[] = ['method' => $payment['method'], 'amount' => $amount];
            }
        }

        $paid = collect($payments)->sum('amount');
        $cashPaid = collect($payments)->where('method', 'cash')->sum('amount');
        $cardPaid = collect($payments)->where('method', 'card')->sum('amount');
        $walletPaid = collect($payments)->where('method', 'wallet')->sum('amount');

        // ============================================================
        // ✅ إنشاء الفاتورة
        // ============================================================
        $representative = $request->filled('sales_representative_id')
            ? SalesRepresentative::query()->find($request->integer('sales_representative_id'))
            : null;
        if ($request->filled('sales_representative_id') && !$representative) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sales_representative_id' => 'مندوب المبيعات غير موجود في مساحة العمل الحالية.',
            ]);
        }
        $commissionRate = (float) ($representative?->commission_rate ?? 0);
        $invoice = Invoice::create([
            'invoice_number'   => $invoiceNumber,
            'customer_id'      => $request->customer_id,
            'sales_representative_id' => $request->sales_representative_id,
            'cashier_id'       => $cashierId,
            'branch_id'        => $branchId ?: null,
            'treasury_id'      => $treasuryId,
            'cashier_shift_id' => $shift?->id,
            'total_amount'     => $netTotal,
            'discount_percentage' => $effectiveDiscountPercentage,
            'discount_amount'  => $totalDiscountAmount,
            'extra_charge'     => $pricing['extra_charge'],
            'is_complimentary' => $isComplimentary,
            'commission_rate_snapshot' => $commissionRate,
            'commission_amount_snapshot' => round($netTotal * $commissionRate / 100, 2),
            'paid_amount'      => $paid,
            'cash_received_amount' => $cashReceived > 0 ? $cashReceived : null,
            'remaining_amount' => $netTotal - $paid,
            'status'           => $paid >= $netTotal ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);

        // ============================================================
        // ✅ إضافة العناصر
        // ============================================================
        foreach ($request->items as $item) {
            $product = Product::query()->lockForUpdate()->find($item['product_id']);

            if (!$product) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => "المنتج ID {$item['product_id']} غير موجود"
                ], 400);
            }

            $stockUsage = (float) $item['quantity'] * (float) ($item['meter_quantity'] ?? 1);
            $warehouseStocks = DB::table('product_warehouse')
                ->join('warehouses', 'warehouses.id', '=', 'product_warehouse.warehouse_id')
                ->where('product_warehouse.product_id', $product->id)
                ->where('warehouses.branch_id', $branchId)
                ->where('warehouses.tenant_id', $tenantId)
                ->whereNull('warehouses.deleted_at')
                ->orderBy('warehouses.id')
                ->lockForUpdate()
                ->get(['product_warehouse.warehouse_id', 'product_warehouse.stock']);
            $availableStock = (float) $warehouseStocks->sum(fn ($warehouse) => (float) $warehouse->stock);

            if ($availableStock < $stockUsage) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => "الكمية المتوفرة في مخازن الفرع للمنتج '{$product->name}' أقل من المطلوبة. المتاح: {$availableStock}، المطلوب: {$stockUsage}."
                ], 400);
            }

            $tenantWarehouseStock = (float) DB::table('product_warehouse')
                ->join('warehouses', 'warehouses.id', '=', 'product_warehouse.warehouse_id')
                ->where('product_warehouse.product_id', $product->id)
                ->where('warehouses.tenant_id', $tenantId)
                ->whereNull('warehouses.deleted_at')
                ->sum('product_warehouse.stock');
            $product->stock = $tenantWarehouseStock;
            $product->save();

            $invoice->items()->create([
                'product_id'   => $item['product_id'],
                'product_name' => $item['product_name'] ?? $product->name,
                'color'        => $item['color'] ?? null,
                'size'         => $item['size'] ?? ($item['vehicle_size'] ?? null),
                'quantity'     => $item['quantity'],
                'price'        => $item['price'],
                'total'        => round(((float) $item['price'] * (float) $item['quantity']) * (1 - (float) ($item['discount_percentage'] ?? 0) / 100), 2),
                'discount_percentage' => (float) ($item['discount_percentage'] ?? 0),
                'discount_amount' => round(((float) $item['price'] * (float) $item['quantity']) * (float) ($item['discount_percentage'] ?? 0) / 100, 2),
            ]);

            $remainingStock = $stockUsage;
            foreach ($warehouseStocks as $warehouseStock) {
                $quantityFromWarehouse = min($remainingStock, (float) $warehouseStock->stock);
                if ($quantityFromWarehouse <= 0) {
                    continue;
                }

                app(\App\Services\InventoryMovementService::class)->apply([
                    'product_id' => $product->id,
                    'branch_id' => $branchId,
                    'warehouse_id' => (int) $warehouseStock->warehouse_id,
                    // POS sales use product/warehouse stock only; do not
                    // create or decrement exact variant stock records.
                    'track_variant_stock' => false,
                    'movement_type' => 'sale',
                    'quantity_delta' => -$quantityFromWarehouse,
                    'reference_type' => Invoice::class,
                    'reference_id' => $invoice->id,
                    'unit_cost' => (float) ($product->cost ?? 0),
                    'notes' => "POS invoice {$invoice->invoice_number}",
                ]);

                $remainingStock -= $quantityFromWarehouse;
                if ($remainingStock <= 0) {
                    break;
                }
            }
            if ($product->automotiveService) {
                $product->automotiveService->decrement('stock_quantity', $stockUsage);
            }
        }

        // ============================================================
        // ✅ إضافة المدفوعات
        // ============================================================
        foreach ($payments as $payment) {
            $invoice->payments()->create([
                'method' => $payment['method'],
                'amount' => $payment['amount'],
                'employee_id' => $user instanceof \App\Models\Employee ? $user->id : $cashierId,
            ]);
        }

        // ============================================================
        // ✅ إيداع المدفوعات النقدية في الخزينة
        // ============================================================
        if ($cashPaid > 0 && $treasuryId) {
            $treasury = Treasury::query()
                ->withoutGlobalScope('branch')
                ->lockForUpdate()
                ->findOrFail($treasuryId);
            $treasury->increment('balance', $cashPaid);

            TreasuryTransaction::create([
                'treasury_id' => $treasuryId,
                'reference_type' => Invoice::class,
                'reference_id' => $invoice->id,
                'type' => 'in',
                'amount' => $cashPaid,
                'description' => "فاتورة مبيعات رقم {$invoice->invoice_number}",
                'created_by' => $user instanceof User ? $user->id : null,
            ]);
        }

        // ============================================================
        // ✅ ✅ ✅ تحديث نقاط الولاء (مع Logging)
        // ============================================================
        $loyaltySetting = LoyaltySetting::first();
        $pointsPerCurrency = (float) ($loyaltySetting?->points ?? 0);
        if ($loyaltySetting && $pointsPerCurrency > 0 && $request->customer_id) {
            $customer = Customer::find($request->customer_id);
            if ($customer) {
                $earnedPoints = floor($paid / $pointsPerCurrency);
                $oldPoints = $customer->point ?? 0;
                $newPoints = $oldPoints + $earnedPoints;
                $customer->point = $newPoints;
                $customer->last_paid_amount = $paid;
                $customer->save();
            }
        }

        // ============================================================
        // ✅ تحديث مبيعات الوردية
        // ============================================================
        if ($shift) {
            $shift->update([
                'cash_sales'   => ($shift->cash_sales ?? 0) + $cashPaid,
                'card_sales'   => ($shift->card_sales ?? 0) + $cardPaid,
                'wallet_sales' => ($shift->wallet_sales ?? 0) + $walletPaid,
            ]);
        }

        $accounting = app(PosAccountingPostingService::class);
        $invoiceForPosting = $invoice->fresh(['customer', 'salesRepresentative', 'items.product']);
        $accounting->postSale($invoiceForPosting);
        foreach ($invoice->payments()->get() as $payment) {
            $accounting->postPayment($invoiceForPosting, $payment);
        }
        $accounting->postCogs($invoiceForPosting);
        $accounting->postCommission($invoiceForPosting);

        DB::commit();

        return response()->json([
            'status'  => true,
            'message' => 'Invoice created successfully',
            'data'    => new InvoiceResource(
                $invoice->fresh([
                    'items',
                    'payments',
                    'customer',
                    'branch',
                    'shift',
                    'salesRepresentative',
                    'cashier',
                    'treasury'
                ])
            )
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('❌ Invoice Store Error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'request' => $request->all()
        ]);

        return response()->json([
            'status'  => false,
            'message' => 'حدث خطأ أثناء إنشاء الفاتورة: ' . $e->getMessage(),
        ], 500);
    }
}

    public function searchByInvoiceNumber(Request $request)
    {
        $request->validate([
            'invoice_number' => 'required|string'
        ]);

        try {
            $invoiceNumber = trim((string) $request->query('invoice_number'));
            $digits = preg_replace('/\D+/', '', $invoiceNumber);
            $normalized = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper($invoiceNumber));
            $invoice = Invoice::with([
                'items.product',
                'customer',
                'branch',
                'cashier',
                'treasury',
                'salesRepresentative'
            ])
                ->where(function ($query) use ($invoiceNumber, $digits, $normalized): void {
                    $query->where('invoice_number', $invoiceNumber);
                    if ($digits !== '') $query->orWhere('invoice_number', 'like', '%' . $digits);
                    if ($normalized !== '' && $normalized !== strtoupper($invoiceNumber)) {
                        $query->orWhereRaw("REPLACE(REPLACE(UPPER(invoice_number), '-', ''), ' ', '') LIKE ?", ['%' . $normalized]);
                    }
                })
                ->latest('id')
                ->first();

            if (!$invoice) {
                return response()->json([
                    'status'  => false,
                    'message' => "لا توجد فاتورة برقم {$request->query('invoice_number')}"
                ], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'تم العثور على الفاتورة',
                'data'    => new InvoiceResource($invoice)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء البحث',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $invoice = Invoice::with([
                'items',
                'payments',
                'customer',
                'branch',
                'shift',
                'salesRepresentative',
                'cashier',
                'treasury'
            ])->findOrFail($id);

            return response()->json([
                'status' => true,
                'data' => new InvoiceResource($invoice)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function makeComplimentary(Request $request, Invoice $invoice, PosAccountingPostingService $posting)
    {
        abort_unless($this->mayApplyPosDiscount($request->user()), 403, 'لا تملك صلاحية تحويل الفاتورة إلى مجاملة.');

        try {
            $updatedInvoice = $posting->makeComplimentary($invoice);
            return response()->json([
                'status' => true,
                'message' => 'تم تحويل الفاتورة إلى مجاملة ورد المبالغ المسددة.',
                'data' => new InvoiceResource($updatedInvoice->load([
                    'items', 'payments', 'customer', 'branch', 'shift', 'salesRepresentative', 'cashier', 'treasury', 'returns',
                ])),
            ]);
        } catch (\RuntimeException $error) {
            return response()->json(['status' => false, 'message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            Log::error('POS complimentary conversion failed', [
                'invoice_id' => $invoice->id,
                'error' => $error->getMessage(),
            ]);
            return response()->json(['status' => false, 'message' => 'تعذر تحويل الفاتورة إلى مجاملة.'], 500);
        }
    }

    private function mayApplyPosDiscount(?object $user): bool
    {
        if (!$user) return false;
        if ($user instanceof Admin || (bool) ($user->super_admin ?? false)) return true;
        $role = $user->role ?? null;
        $roleName = strtolower((string) (is_object($role) ? ($role->name ?? '') : ($role ?? '')));
        return in_array($roleName, ['admin', 'administrator', 'tenant_admin', 'company_admin'], true)
            || (method_exists($user, 'hasPermission') && $user->hasPermission('sales.pos_discount.apply'));
    }
}
