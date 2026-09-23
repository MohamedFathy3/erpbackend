<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSalesInvoiceRequest;
use App\Http\Requests\CollectSalesInvoicePaymentRequest;
use App\Http\Resources\SalesInvoiceResource;
use App\Models\Customer;
use App\Models\LoyaltySetting;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesInvoicePayment;
use App\Models\Treasury;
use App\Models\Bank;
use App\Models\Transfer;
use App\Models\Tax;
use App\Services\WorkflowPostingService;
use App\Models\TreasuryTransaction;
use App\Services\InventoryMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesInvoiceController extends Controller
{
    /**
     * Store a newly created sales invoice in storage.
     */
    public function store(StoreSalesInvoiceRequest $request, WorkflowPostingService $posting)
    {
        DB::beginTransaction();

        try {
            $invoiceNumber = 'SI-' . now()->format('Ymd') . '-' . rand(1000, 9999);

            $subtotal = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);

                // unit_id is the catalog unit ID; product_unit_id is the product-specific
                // pivot row ID. Accept either payload shape, but always persist the pivot ID.
                $productUnitId = $item['product_unit_id'] ?? null;
                if ($productUnitId) {
                    $productUnitBelongs = DB::table('product_units')
                        ->where('id', $productUnitId)
                        ->where('product_id', $product->id)
                        ->exists();
                    if (!$productUnitBelongs) {
                        $productUnitId = null;
                    }
                }
                if (!$productUnitId && !empty($item['unit_id'])) {
                    $productUnitId = DB::table('product_units')
                        ->where('product_id', $product->id)
                        ->where('unit_id', $item['unit_id'])
                        ->value('id');
                }
                if (!$productUnitId && (!empty($item['product_unit_id']) || !empty($item['unit_id']))) {
                    throw new \RuntimeException('Selected unit is not configured for this product');
                }

                // حساب سعر المنتج بعد خصمه الفردي
                $itemDiscountPercentage = $item['discount_percentage'] ?? 0;
                $itemDiscountAmount = ($item['price'] * $item['quantity'] * $itemDiscountPercentage) / 100;
                $itemTotalAfterDiscount = ($item['price'] * $item['quantity']) - $itemDiscountAmount;

                $itemsData[] = [
                    'product_id' => $item['product_id'],
                    'product_unit_id' => $productUnitId,
                    'size_id' => $item['size_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'color_id' => $item['color_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'discount_percentage' => $itemDiscountPercentage,
                    'discount_amount' => $itemDiscountAmount,
                    'total' => $itemTotalAfterDiscount,
                ];

                $subtotal += $itemTotalAfterDiscount;
            }

            // خصم الفاتورة
            $discountPercentage = $request->discount_percentage ?? 0;
            $discountAmount = ($subtotal * $discountPercentage) / 100;
            $taxAmount = (float) ($request->input('tax_amount') ?? 0);
            if (!$request->has('tax_amount') && $request->tax_id) {
                $taxRate = (float) (Tax::find($request->tax_id)?->rate ?? 0);
                $taxAmount = (($subtotal - $discountAmount) * $taxRate) / 100;
            }
            $netTotal = $subtotal - $discountAmount + $taxAmount;

            // إنشاء الفاتورة
            $invoice = SalesInvoice::create([
                'invoice_number' => $invoiceNumber,
                'customer_id' => $request->customer_id,
                'treasury_id' => in_array($request->payment_method, ['cash', 'card', 'check', 'credit_card', 'credit'], true) ? $request->treasury_id : null,
                'bank_id' => in_array($request->payment_method, ['bank', 'bank_transfer'], true) ? $request->bank_id : null,
                'sales_representative_id' => $request->sales_representative_id,
                'branch_id' => $request->branch_id,
                'warehouse_id' => $request->warehouse_id,
                'currency_id' => $request->currency_id,
                'tax_id' => $request->tax_id,
                'payment_method' => $request->payment_method,
                'invoice_date' => $request->invoice_date ?? now()->format('Y-m-d'),
                'due_date' => $request->due_date,
                'note' => $request->note,
                'total_amount' => $subtotal,
                'discount_percentage' => $discountPercentage,
                'discount_amount' => $discountAmount,
                'net_total' => $netTotal,
                'tax_amount' => $taxAmount,
                'paid_amount' => $request->payment_method === 'credit' ? 0 : $netTotal,
                'payment_status' => $request->payment_method === 'credit' ? 'unpaid' : 'paid',
            ]);

            // إنشاء عناصر الفاتورة وتسجيل حركة المخزون في نفس المعاملة
            foreach ($itemsData as $itemData) {
                SalesInvoiceItem::create(array_merge(
                    ['sales_invoice_id' => $invoice->id],
                    $itemData
                ));

                app(InventoryMovementService::class)->apply([
                    'product_id' => $itemData['product_id'],
                    'product_unit_id' => $itemData['product_unit_id'],
                    'size_id' => $itemData['size_id'],
                    'color_id' => $itemData['color_id'],
                    'branch_id' => $request->branch_id,
                    'warehouse_id' => $request->warehouse_id,
                    'movement_type' => 'sale',
                    'quantity_delta' => -$itemData['quantity'],
                    'reference_type' => SalesInvoice::class,
                    'reference_id' => $invoice->id,
                    'notes' => "Sales invoice {$invoice->invoice_number}",
                ]);

            }

            // تحريك الرصيد فقط في طرق الدفع الفورية.
            if (in_array($request->payment_method, ['cash', 'card', 'check', 'credit_card'], true) && $request->treasury_id) {
                $treasury = Treasury::query()->lockForUpdate()->findOrFail($request->treasury_id);
                $treasury->increment('balance', $netTotal);

                TreasuryTransaction::create([
                    'treasury_id' => $treasury->id,
                    'reference_type' => SalesInvoice::class,
                    'reference_id' => $invoice->id,
                    'type' => 'in',
                    'amount' => $netTotal,
                    'description' => "تحصيل فاتورة مبيعات رقم {$invoice->invoice_number}",
                    'created_by' => optional(auth()->user())->id,
                ]);
            }

            if (in_array($request->payment_method, ['bank', 'bank_transfer'], true)) {
                $bank = Bank::query()->lockForUpdate()->findOrFail($request->bank_id);
                $bank->increment('balance', $netTotal);
                Transfer::create([
                    'type' => 'bank_deposit',
                    'to_bank_id' => $bank->id,
                    'amount' => $netTotal,
                    'currency' => $invoice->currency?->code ?? 'EGP',
                    'notes' => "تحويل بنكي من فاتورة مبيعات {$invoice->invoice_number}",
                    'created_by' => optional(auth()->user())->id,
                ]);
            }

            if ((float) $invoice->paid_amount > 0) {
                SalesInvoicePayment::create([
                    'sales_invoice_id' => $invoice->id,
                    'payment_method' => $request->payment_method,
                    'amount' => $invoice->paid_amount,
                    'treasury_id' => $invoice->treasury_id,
                    'bank_id' => $invoice->bank_id,
                    'created_by' => null,
                    'employee_id' => auth()->user() instanceof \App\Models\Employee ? auth()->id() : null,
                ]);
            }

            // ============================================================
            // ✅ ✅ ✅ إضافة نقاط الولاء
            // ============================================================
            $this->updateLoyaltyPoints($request->customer_id, (float) $invoice->paid_amount);

            $journal = $posting->postSale($invoice->load('items.product'));
            $invoice->update(['posting_journal_entry_id' => $journal?->id, 'workflow_status' => $journal ? 'posted' : 'pending_finance']);

            DB::commit();

            return new SalesInvoiceResource(
                $invoice->load(
                    'items.product',
                    'items.unit',
                    'items.color',
                'items.size',
                    'customer',
                    'salesRepresentative',
                    'branch',
                    'warehouse',
                    'currency',
                    'tax'
                    , 'bank'
                )
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('❌ Sales Invoice Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to create invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ============================================================
    // ✅ دالة تحديث نقاط الولاء
    // ============================================================
    
    private function updateLoyaltyPoints($customerId, $paidAmount, $previousPaidAmount = 0)
    {
        Log::info('⭐ ========== LOYALTY POINTS START (Sales Invoice) ==========');
        Log::info('📊 Customer ID: ' . $customerId);
        Log::info('💰 Paid Amount: ' . $paidAmount);

        try {
            // 1️⃣ جلب إعدادات الولاء
            $loyaltySetting = LoyaltySetting::first();
            
            Log::info('📊 Loyalty Settings:', [
                'exists' => $loyaltySetting ? 'Yes' : 'No',
                'point_value' => $loyaltySetting?->point_value,
                'silver' => $loyaltySetting?->silver,
                'gold' => $loyaltySetting?->gold,
                'platinum' => $loyaltySetting?->platinum
            ]);

            $pointsPerCurrency = (float) ($loyaltySetting?->points ?? 0);
            if (!$loyaltySetting || $pointsPerCurrency <= 0) {
                Log::warning('⚠️ Loyalty settings not found or point_value = 0');
                return;
            }

            // 2️⃣ جلب العميل
            $customer = Customer::find($customerId);
            
            if (!$customer) {
                Log::error('❌ Customer not found for ID: ' . $customerId);
                return;
            }

            Log::info('👤 Customer found:', [
                'id' => $customer->id,
                'name' => $customer->name,
                'current_points' => $customer->point ?? 0
            ]);

            // 3️⃣ points is the spend threshold (for example 1000 currency = 1 point).
            // point_value is the redemption discount percentage and must not multiply sales.
            $earnedPoints = max(
                0,
                floor(($previousPaidAmount + $paidAmount) / $pointsPerCurrency)
                    - floor($previousPaidAmount / $pointsPerCurrency)
            );
            $oldPoints = $customer->point ?? 0;
            $newPoints = max(0, $oldPoints + $earnedPoints);

            Log::info('🧮 Points Calculation:', [
                'paid_amount' => $paidAmount,
                'points_per_currency' => $pointsPerCurrency,
                'previous_paid_amount' => $previousPaidAmount,
                'earned_points' => $earnedPoints,
                'old_points' => $oldPoints,
                'new_points' => $newPoints
            ]);

            // 4️⃣ تحديث النقاط
            $customer->point = $newPoints;
            $customer->last_paid_amount = $paidAmount;
            $customer->save();

            Log::info('✅ Points saved successfully!', [
                'customer_id' => $customer->id,
                'new_points' => $customer->point
            ]);

            // 5️⃣ تحديث مستوى العميل
            $this->updateCustomerLevel($customer, $loyaltySetting);

        } catch (\Exception $e) {
            Log::error('❌ Error updating loyalty points: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }

        Log::info('⭐ ========== LOYALTY POINTS END ==========');
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

    // ============================================================
    // ✅ جلب جميع فواتير المبيعات
    // ============================================================
    public function invoiceIndex(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $orderBy = $request->input('orderBy', 'id');
            $orderByDirection = $request->input('orderByDirection', 'desc');
            $perPage = $request->input('perPage', 10);
            $paginate = $request->boolean('paginate', true);

            $query = SalesInvoice::with([
                'customer',
                'salesRepresentative',
                'branch',
                'warehouse',
                'currency',
                'tax',
                'treasury',
                'bank'
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

            if (!empty($filters['sales_representative_id'])) {
                $query->where('sales_representative_id', $filters['sales_representative_id']);
            }

            if (!empty($filters['branch_id'])) {
                $query->where('branch_id', $filters['branch_id']);
            }

            if (!empty($filters['warehouse_id'])) {
                $query->where('warehouse_id', $filters['warehouse_id']);
            }

            if (!empty($filters['payment_method'])) {
                $query->where('payment_method', $filters['payment_method']);
            }

            if (!empty($filters['currency_id'])) {
                $query->where('currency_id', $filters['currency_id']);
            }

            if (!empty($filters['tax_id'])) {
                $query->where('tax_id', $filters['tax_id']);
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
            // PAGINATION
            // =========================
            if ($paginate) {
                $invoices = $query->paginate($perPage);

                return response()->json([
                    'data' => SalesInvoiceResource::collection($invoices->items()),
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
                    'message' => 'Sales invoices fetched successfully',
                    'status' => 200,
                ]);
            }

            // =========================
            // NON PAGINATED
            // =========================
            $invoices = $query->get();

            return response()->json([
                'data' => SalesInvoiceResource::collection($invoices),
                'links' => null,
                'meta' => null,
                'result' => 'Success',
                'message' => 'Sales invoices fetched successfully',
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
    // ✅ عرض فاتورة مبيعات واحدة
    // ============================================================
    public function show($id)
    {
        try {
            $invoice = SalesInvoice::with([
                'items.product',
                'items.unit',
                'items.color',
                'items.size',
                'customer',
                'salesRepresentative',
                'branch',
                'warehouse',
                'currency',
                'tax',
                'treasury',
                'bank',
            ])->findOrFail($id);

            return response()->json([
                'data' => new SalesInvoiceResource($invoice),
                'result' => 'Success',
                'message' => 'Sales invoice fetched successfully',
                'status' => 200,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'result' => 'Error',
                'message' => 'Sales invoice not found',
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

    public function collectPayment(CollectSalesInvoicePaymentRequest $request, $id, WorkflowPostingService $posting)
    {
        DB::beginTransaction();
        try {
            $invoice = SalesInvoice::lockForUpdate()->findOrFail($id);
            $amount = (float) $request->amount;
            $remaining = (float) $invoice->net_total - (float) ($invoice->paid_amount ?? 0);
            if ($invoice->payment_status === 'paid' || $amount > $remaining + 0.0001) {
                throw new \RuntimeException('مبلغ التحصيل أكبر من الرصيد المتبقي على الفاتورة.');
            }

            if ($request->payment_method === 'cash') {
                $treasury = Treasury::query()->lockForUpdate()->findOrFail($request->treasury_id);
                $treasury->increment('balance', $amount);
                TreasuryTransaction::create([
                    'treasury_id' => $treasury->id,
                    'reference_type' => SalesInvoice::class,
                    'reference_id' => $invoice->id,
                    'type' => 'in',
                    'amount' => $amount,
                    'description' => "تحصيل آجل لفاتورة {$invoice->invoice_number}",
                    'created_by' => optional(auth()->user())->id,
                ]);
            } else {
                $bank = Bank::query()->lockForUpdate()->findOrFail($request->bank_id);
                $bank->increment('balance', $amount);
                Transfer::create([
                    'type' => 'bank_deposit',
                    'to_bank_id' => $bank->id,
                    'amount' => $amount,
                    'currency' => $invoice->currency?->code ?? 'EGP',
                    'notes' => "تحصيل تحويل بنكي لفاتورة {$invoice->invoice_number}",
                    'created_by' => optional(auth()->user())->id,
                ]);
            }

            $treasuryId = $request->treasury_id ?: $invoice->treasury_id;
            $previousPaid = (float) ($invoice->paid_amount ?? 0);
            $journal = $posting->postCollection($invoice, $amount, $treasuryId);
            SalesInvoicePayment::create([
                'sales_invoice_id' => $invoice->id,
                'payment_method' => $request->payment_method,
                'amount' => $amount,
                'treasury_id' => $treasuryId,
                'bank_id' => $request->bank_id,
                'created_by' => null,
                'employee_id' => auth()->user() instanceof \App\Models\Employee ? auth()->id() : null,
            ]);
            $paid = (float) ($invoice->paid_amount ?? 0) + $amount;
            $invoice->update([
                'paid_amount' => $paid,
                'payment_status' => $paid + 0.0001 >= (float) $invoice->net_total ? 'paid' : 'partial',
                'workflow_status' => $journal ? 'posted' : $invoice->workflow_status,
            ]);
            $this->updateLoyaltyPoints($invoice->customer_id, $amount, $previousPaid);
            DB::commit();

            return response()->json(['status' => true, 'message' => 'تم تحصيل الدفعة وتسجيل الحركة بنجاح', 'data' => new SalesInvoiceResource($invoice->fresh()->load('customer', 'bank', 'treasury', 'items.product'))]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Sales invoice payment collection failed', ['invoice_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, $id, WorkflowPostingService $posting)
    {
        DB::beginTransaction();
        try {
            $invoice = SalesInvoice::with('items')->lockForUpdate()->findOrFail($id);
            if ($invoice->workflow_status === 'cancelled') {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'الفاتورة ملغاة بالفعل'], 422);
            }
            if ($invoice->workflow_status === 'posted' && !$invoice->posting_journal_entry_id) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'لا يمكن إلغاء فاتورة مرحّلة بدون قيد مرتبط'], 422);
            }

            $posting->reverseInvoice($invoice, 'sale');
            if ($invoice->treasury_id && (float) ($invoice->paid_amount ?? 0) > 0) {
                Treasury::whereKey($invoice->treasury_id)->decrement('balance', $invoice->paid_amount);
            }
            if ($invoice->bank_id && (float) ($invoice->paid_amount ?? 0) > 0) {
                Bank::whereKey($invoice->bank_id)->decrement('balance', $invoice->paid_amount);
                Transfer::create([
                    'type' => 'bank_withdraw',
                    'from_bank_id' => $invoice->bank_id,
                    'amount' => $invoice->paid_amount,
                    'currency' => $invoice->currency?->code ?? 'EGP',
                    'notes' => "عكس تحويل فاتورة المبيعات {$invoice->invoice_number}",
                    'created_by' => optional(auth()->user())->id,
                ]);
            }
            $this->updateLoyaltyPoints($invoice->customer_id, -((float) ($invoice->paid_amount ?? 0)));
            $invoice->update(['workflow_status' => 'cancelled']);
            DB::commit();
            return response()->json(['status' => true, 'message' => 'تم إلغاء الفاتورة وعكس أثرها المالي والمخزني', 'data' => new SalesInvoiceResource($invoice->fresh()->load('items.product'))]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Sales invoice cancellation failed', ['invoice_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
