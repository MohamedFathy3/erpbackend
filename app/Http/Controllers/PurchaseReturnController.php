<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReturnRequest;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Transfer;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Services\WorkflowPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseReturnController extends Controller
{
    public function index(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $orderBy = $request->input('orderBy', 'id');
            $orderByDirection = $request->input('orderByDirection', 'desc');
            $perPage = $request->input('perPage', 10);
            $paginate = $request->boolean('paginate', true);

            $query = PurchaseReturn::with([
                'purchaseInvoice',
                'items.product',
                'items.color',
                'items.unit',
                'treasury',
                'currency',
                'warehouse',
            ]);

            // filters
            if (!empty($filters['return_number'])) {
                $query->where('return_number', 'like', '%' . $filters['return_number'] . '%');
            }

            if (!empty($filters['invoice_number'])) {
                $query->whereHas('purchaseInvoice', function ($q) use ($filters) {
                    $q->where('invoice_number', 'like', '%' . $filters['invoice_number'] . '%');
                });
            }

            if (!empty($filters['purchase_invoices_id'])) {
                $query->where('purchase_invoices_id', $filters['purchase_invoices_id']);
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

            if (!empty($filters['reason'])) {
                $query->where('reason', 'like', '%' . $filters['reason'] . '%');
            }

            if (!empty($filters['treasury_id'])) {
                $query->where('treasury_id', $filters['treasury_id']);
            }

            if (!empty($filters['currency_id'])) {
                $query->where('currency_id', $filters['currency_id']);
            }

            if (!empty($filters['warehouse_id'])) {
                $query->where('warehouse_id', $filters['warehouse_id']);
            }

            if (!empty($filters['payment_method'])) {
                $query->where('payment_method', $filters['payment_method']);
            }

            if (!empty($filters['return_date_from'])) {
                $query->whereDate('return_date', '>=', $filters['return_date_from']);
            }

            if (!empty($filters['return_date_to'])) {
                $query->whereDate('return_date', '<=', $filters['return_date_to']);
            }

            $query->orderBy($orderBy, $orderByDirection);

            if ($paginate) {
                $returns = $query->paginate($perPage);

                return response()->json([
                    'data' => PurchaseReturnResource::collection($returns->items()),
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
                    'message' => 'Purchase returns fetched successfully',
                    'status' => 200,
                ]);
            }

            $returns = $query->get();

            return response()->json([
                'data' => PurchaseReturnResource::collection($returns),
                'links' => null,
                'meta' => null,
                'result' => 'Success',
                'message' => 'Purchase returns fetched successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            Log::error('Purchase returns fetch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

public function store(
    StoreReturnRequest $request,
    WorkflowPostingService $posting
) {
    DB::beginTransaction();

    try {

        // ============================================================
        // جلب فاتورة المشتريات
        // ============================================================

        $invoice = PurchaseInvoice::with('items')
            ->lockForUpdate()
            ->find($request->purchase_invoices_id);

        if (!$invoice) {
            throw new \Exception('Invoice not found');
        }

        // ============================================================
        // التأكد أن الفاتورة مدفوعة
        // ============================================================

        if ($invoice->paid_amount <= 0) {
            throw new \Exception(
                'لا يمكن عمل مرتجع لفاتورة غير مدفوعة'
            );
        }

        // ============================================================
        // حساب إجمالي المرتجع
        // ============================================================

        $total = collect($request->items)
            ->sum(function ($item) {
                return
                    (float) $item['quantity'] *
                    (float) $item['unit_price'];
            });

        if ($total > (float) $invoice->paid_amount) {
            throw new \Exception(
                'قيمة المرتجع (' .
                $total .
                ') أكبر من المبلغ المدفوع (' .
                $invoice->paid_amount .
                ')'
            );
        }

        // ============================================================
        // التحقق من الكميات
        // ============================================================

        foreach ($request->items as $item) {

            $productId = $item['product_id'];
            $colorId = $item['color_id'] ?? null;
            $quantity = (float) $item['quantity'];

            // ========================================================
            // 1️⃣ التحقق أن المنتج موجود في الفاتورة الأصلية
            // ========================================================

            $invoiceItem = $invoice->items()
                ->where('product_id', $productId)
                ->where('color_id', $colorId)
                ->first();

            if (!$invoiceItem) {
                throw new \Exception(
                    'المنتج رقم ' .
                    $productId .
                    ' غير موجود في الفاتورة الأصلية'
                );
            }

            // ========================================================
            // 2️⃣ حساب الكمية التي تم إرجاعها سابقًا من نفس الفاتورة
            // ========================================================

            $returnedQuantity = PurchaseReturnItem::whereHas(
                'purchaseReturn',
                function ($q) use ($invoice) {
                    $q->where(
                        'purchase_invoices_id',
                        $invoice->id
                    );
                }
            )
            ->where('product_id', $productId)
            ->where('color_id', $colorId)
            ->sum('quantity');

            // ========================================================
            // 3️⃣ الكمية المتبقية من الفاتورة
            // ========================================================

            $availableInvoiceQuantity =
                (float) $invoiceItem->quantity
                - (float) $returnedQuantity;

            if ($quantity > $availableInvoiceQuantity) {

                throw new \Exception(
                    'الكمية المرتجعة (' .
                    $quantity .
                    ') أكبر من الكمية المتاحة من الفاتورة (' .
                    $availableInvoiceQuantity .
                    ') للمنتج رقم ' .
                    $productId
                );
            }

            // ========================================================
            // 4️⃣ التحقق من وجود المنتج في المخزن
            // ========================================================

           $warehouseStock = DB::table('product_warehouse')
            ->where('product_id', $productId) 
            ->where('warehouse_id', $invoice->warehouse_id)
             ->lockForUpdate() ->first(); if (!$warehouseStock)
              { throw new \Exception( 'لا يمكن تنفيذ المرتجع للمنتج رقم ' 
              . $productId . '. المنتج غير موجود في المخزن رقم ' . $invoice->warehouse_id . 
              '. الكمية المطلوبة للمرتجع: ' . $quantity . '. الكمية الموجودة في المخزن: 0' );
               }

            // ========================================================
            // 5️⃣ قراءة المخزون الحالي
            // ========================================================

            $currentWarehouseStock =
                (float) $warehouseStock->stock;

            // ========================================================
            // 6️⃣ التأكد أن المخزون يكفي للمرتجع
            // ========================================================

        if ($quantity > $currentWarehouseStock)
             { throw new \Exception( 'لا يمكن تنفيذ المرتجع للمنتج رقم '
        . $productId . '. ' . 'الكمية المطلوبة للمرتجع: ' . $quantity . '.
         ' . 'الكمية الموجودة حاليًا في المخزن: ' . $currentWarehouseStock 
         . '. ' . 'الكمية الناقصة: ' . ($quantity - $currentWarehouseStock) ); 
         }
        }

        // ============================================================
        // إنشاء المرتجع
        // ============================================================

        $return = PurchaseReturn::create([
            'purchase_invoices_id' =>
                $request->purchase_invoices_id,

            'return_number' =>
                'PR-' .
                now()->format('Ymd') .
                '-' .
                rand(1000, 9999),

            'total_amount' =>
                $total,

            'paid_amount' =>
                $total,

            'payment_method' =>
                $invoice->payment_method,

            'return_date' =>
                $request->return_date
                ?? now()->format('Y-m-d'),

            'reason' =>
                $request->reason,

            'treasury_id' =>
                $invoice->treasury_id,

            'currency_id' =>
                $invoice->currency_id,

            'warehouse_id' =>
                $invoice->warehouse_id,
        ]);

        // ============================================================
        // المنتجات وتحديث المخزون
        // ============================================================

        foreach ($request->items as $item) {

            $quantity =
                (float) $item['quantity'];

            $lineTotal =
                $quantity *
                (float) $item['unit_price'];

            // ========================================================
            // إنشاء بند المرتجع
            // ========================================================

            PurchaseReturnItem::create([
                'purchase_return_id' =>
                    $return->id,

                'product_id' =>
                    $item['product_id'],

                'product_unit_id' =>
                    $item['product_unit_id'] ?? null,

                'color_id' =>
                    $item['color_id'] ?? null,

                'quantity' =>
                    $quantity,

                'unit_price' =>
                    $item['unit_price'],

                'total_price' =>
                    $lineTotal,
            ]);

            // ========================================================
            // تحديث المخزون العام
            // ========================================================

            $product = Product::lockForUpdate()
                ->find($item['product_id']);

            if ($product) {

                $product->decrement(
                    'stock',
                    $quantity
                );
            }

            // ========================================================
            // تحديث product_unit_colors
            // ========================================================

            if (
                !empty($item['product_unit_id']) &&
                !empty($item['color_id'])
            ) {

                $productUnit = DB::table('product_units')
                    ->where(
                        'product_id',
                        $item['product_id']
                    )
                    ->where(
                        'unit_id',
                        $item['product_unit_id']
                    )
                    ->first();

                if ($productUnit) {

                    DB::table('product_unit_colors')
                        ->where(
                            'product_unit_id',
                            $productUnit->id
                        )
                        ->where(
                            'color_id',
                            $item['color_id']
                        )
                        ->decrement(
                            'stock',
                            $quantity
                        );
                }
            }

            // ========================================================
            // تحديث مخزون المخزن
            // ========================================================

            DB::table('product_warehouse')
                ->where(
                    'product_id',
                    $item['product_id']
                )
                ->where(
                    'warehouse_id',
                    $invoice->warehouse_id
                )
                ->decrement(
                    'stock',
                    $quantity
                );
        }

        // ============================================================
        // معالجة الخزينة
        // ============================================================

        if (
            $invoice->payment_method === 'cash' &&
            $invoice->treasury_id &&
            $total > 0
        ) {

            $treasury = Treasury::lockForUpdate()
                ->find($invoice->treasury_id);

            if (!$treasury) {
                throw new \Exception(
                    'الخزنة غير موجودة'
                );
            }

            // زيادة رصيد الخزينة
            $treasury->increment(
                'balance',
                $total
            );

            TreasuryTransaction::create([
                'treasury_id' =>
                    $invoice->treasury_id,

                'reference_type' =>
                    PurchaseReturn::class,

                'reference_id' =>
                    $return->id,

                'type' =>
                    'in',

                'amount' =>
                    $total,

                'description' =>
                    "مرتجع فاتورة مشتريات رقم {$invoice->invoice_number}",

                'created_at' =>
                    now(),
            ]);

            // تخفيض المبلغ المدفوع من الفاتورة
            $invoice->decrement(
                'paid_amount',
                $total
            );
        }

        // ============================================================
        // القيد المحاسبي
        // ============================================================

        $journal = $posting->postReturn(
            $return,
            'purchase_return',
            $total,
            $invoice->treasury_id
        );

        $return->update([
            'posting_journal_entry_id' =>
                $journal?->id,

            'workflow_status' =>
                $journal
                    ? 'posted'
                    : 'pending_finance',
        ]);

        // ============================================================
        // Commit
        // ============================================================

        DB::commit();

        // ============================================================
        // تحميل العلاقات
        // ============================================================

        $return->load([
            'items.product',
            'items.color',
            'items.unit',
            'purchaseInvoice',
            'treasury',
            'currency',
            'warehouse',
        ]);

        return response()->json([
            'data' =>
                new PurchaseReturnResource($return),

            'result' =>
                'Success',

            'message' =>
                'Purchase return created successfully',

            'status' =>
                200,
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        Log::error(
            'Purchase return creation failed',
            [
                'error' =>
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
                'Failed to create purchase return',

            'error' =>
                config('app.debug')
                    ? $e->getMessage()
                    : null,

            'status' =>
                500,

        ], 500);
    }
}


    public function show($id)
    {
        try {
            $return = PurchaseReturn::with([
                'items.product',
                'items.color',
                'items.unit',
                'purchaseInvoice',
                'purchaseInvoice.items',
                'treasury',
                'currency',
                'warehouse',
                'treasuryTransactions'
            ])->findOrFail($id);

            return response()->json([
                'data' => new PurchaseReturnResource($return),
                'result' => 'Success',
                'message' => 'Purchase return fetched successfully',
                'status' => 200,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'result' => 'Error',
                'message' => 'Purchase return not found',
                'status' => 404,
            ], 404);

        } catch (\Exception $e) {
            Log::error('Purchase return fetch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id
            ]);

            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    public function destroy($id, WorkflowPostingService $posting)
    {
        DB::beginTransaction();

        try {
            $return = PurchaseReturn::with('items')->findOrFail($id);
            $invoice = PurchaseInvoice::find($return->purchase_invoices_id);
            if ($return->workflow_status === 'cancelled') {
                throw new \RuntimeException('مرتجع المشتريات ملغى بالفعل');
            }
            $posting->reverseInvoice($return->load('purchaseInvoice'), 'purchase_return');
            if ($return->treasury_id && $return->total > 0) {
                Treasury::whereKey($return->treasury_id)->decrement('balance', $return->total);
            }
            if ($invoice) {
                $invoice->increment('paid_amount', $return->total);
            }
            $return->update(['workflow_status' => 'cancelled']);

            DB::commit();

            return response()->json([
                'result' => 'Success',
                'message' => 'Purchase return deleted successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Purchase return deletion failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id
            ]);

            return response()->json([
                'result' => 'Error',
                'message' => 'Failed to delete purchase return',
                'error' => config('app.debug') ? $e->getMessage() : null,
                'status' => 500,
            ], 500);
        }
    }
}
