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
    WorkflowPostingService $posting,
    \App\Services\InventoryMovementService $inventory
) {
    try {
        $return = DB::transaction(function () use ($request, $posting, $inventory) {
            $invoice = PurchaseInvoice::with('items')
                ->lockForUpdate()
                ->findOrFail($request->purchase_invoices_id);

            if (!$invoice->warehouse_id) {
                throw new \RuntimeException('لا يمكن تنفيذ المرتجع: الفاتورة لا تحتوي على مخزن.');
            }

            $paymentMethod = $request->input('payment_method', 'cash');
            $paymentMethod = $paymentMethod === 'cash' ? 'cash' : 'credit';
            $warehouseId = (int) $invoice->warehouse_id;
            $returnItems = [];
            $requestedByInvoiceItem = [];

            foreach ($request->items as $input) {
                $productId = (int) $input['product_id'];
                $candidateItems = $invoice->items->where('product_id', $productId)->values();
                foreach (['product_unit_id', 'color_id', 'size_id', 'product_variant_id'] as $field) {
                    if (array_key_exists($field, $input)) {
                        $candidateItems = $candidateItems->filter(fn ($line) => (int) ($line->{$field} ?? 0) === (int) ($input[$field] ?? 0))->values();
                    }
                }
                if ($candidateItems->count() !== 1) {
                    throw new \RuntimeException($candidateItems->isEmpty()
                        ? "المنتج رقم {$productId} أو مواصفته غير موجودة في الفاتورة الأصلية."
                        : "المنتج رقم {$productId} له أكثر من وحدة/لون في الفاتورة؛ أرسل مواصفات الصنف كاملة.");
                }

                $invoiceItem = $candidateItems->first();
                $quantity = (float) $input['quantity'];
                $requestedByInvoiceItem[$invoiceItem->id] = ($requestedByInvoiceItem[$invoiceItem->id] ?? 0) + $quantity;

                $returnedQuery = PurchaseReturnItem::query()
                    ->where('product_id', $productId)
                    ->where('product_unit_id', $invoiceItem->product_unit_id)
                    ->where('color_id', $invoiceItem->color_id)
                    ->where('size_id', $invoiceItem->size_id)
                    ->where('product_variant_id', $invoiceItem->product_variant_id)
                    ->whereHas('purchaseReturn', function ($q) use ($invoice) {
                        $q->where('purchase_invoices_id', $invoice->id)
                            ->where(function ($status) {
                                $status->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'cancelled');
                            });
                    });
                $alreadyReturned = (float) $returnedQuery->sum('quantity');
                $totalRequested = $requestedByInvoiceItem[$invoiceItem->id];
                $available = max(0, (float) $invoiceItem->quantity - $alreadyReturned);
                if ($totalRequested > $available) {
                    throw new \RuntimeException("الكمية المطلوبة لإرجاع المنتج {$productId} هي {$totalRequested} والمتاح المتبقي من الفاتورة {$available} فقط.");
                }

                $unitPrice = (float) $invoiceItem->price;
                if ((float) $invoiceItem->quantity > 0 && (float) $invoiceItem->total > 0) {
                    $unitPrice = (float) $invoiceItem->total / (float) $invoiceItem->quantity;
                }
                $returnItems[] = [
                    'invoice_item' => $invoiceItem,
                    'product_id' => $productId,
                    'product_unit_id' => $invoiceItem->product_unit_id,
                    'color_id' => $invoiceItem->color_id,
                    'size_id' => $invoiceItem->size_id,
                    'product_variant_id' => $invoiceItem->product_variant_id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => round($quantity * $unitPrice, 2),
                ];
            }

            $total = round(array_sum(array_column($returnItems, 'total')), 2);
            $paidAmount = (float) $invoice->paid_amount;
            $remainingDue = max(0, (float) $invoice->total_amount - $paidAmount);
            $settlementLimit = $paymentMethod === 'cash' ? $paidAmount : $remainingDue;
            if ($total > $settlementLimit) {
                throw new \RuntimeException($paymentMethod === 'cash'
                    ? "قيمة رد النقد {$total} تتجاوز ما تم سداده للمورد ({$paidAmount})."
                    : "قيمة المرتجع الآجل {$total} تتجاوز رصيد الفاتورة المستحق ({$remainingDue}).");
            }

            $return = PurchaseReturn::create([
                'purchase_invoices_id' => $invoice->id,
                'return_number' => 'PR-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                'total_amount' => $total,
                'paid_amount' => $paymentMethod === 'cash' ? $total : 0,
                'payment_method' => $paymentMethod,
                'return_date' => $request->input('return_date', now()->toDateString()),
                'reason' => $request->reason,
                'treasury_id' => $paymentMethod === 'cash' ? $invoice->treasury_id : null,
                'currency_id' => $invoice->currency_id,
                'warehouse_id' => $warehouseId,
            ]);

            foreach ($returnItems as $item) {
                PurchaseReturnItem::create([
                    'purchase_return_id' => $return->id,
                    'product_id' => $item['product_id'],
                    'product_unit_id' => $item['product_unit_id'],
                    'color_id' => $item['color_id'],
                    'size_id' => $item['size_id'],
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => $item['total'],
                ]);

                // Service enforces non-negative aggregate, warehouse and variant stock and records the movement.
                $inventory->apply([
                    'product_id' => $item['product_id'],
                    'product_unit_id' => $item['product_unit_id'],
                    'color_id' => $item['color_id'],
                    'size_id' => $item['size_id'],
                    'branch_id' => $invoice->branch_id,
                    'warehouse_id' => $warehouseId,
                    'movement_type' => 'purchase_return',
                    'quantity_delta' => -$item['quantity'],
                    'reference_type' => PurchaseReturn::class,
                    'reference_id' => $return->id,
                    'notes' => "Purchase return {$return->return_number} from invoice {$invoice->invoice_number}",
                ]);
            }

            if ($paymentMethod === 'cash' && $total > 0) {
                $treasury = Treasury::query()->lockForUpdate()->findOrFail($invoice->treasury_id);
                $treasury->increment('balance', $total);
                TreasuryTransaction::create([
                    'treasury_id' => $treasury->id,
                    'reference_type' => PurchaseReturn::class,
                    'reference_id' => $return->id,
                    'type' => 'in',
                    'amount' => $total,
                    'description' => "استرداد نقدي من مرتجع فاتورة المشتريات {$invoice->invoice_number}",
                ]);
                $invoice->update(['paid_amount' => max(0, $paidAmount - $total)]);
            }

            // The return is a separate document; its amount is exposed on the invoice response from the return ledger.
            $journal = $posting->postReturn($return->load('purchaseInvoice.supplier'), 'purchase_return', $total, $paymentMethod === 'cash' ? (int) $invoice->treasury_id : null);
            $return->update([
                'posting_journal_entry_id' => $journal?->id,
                'workflow_status' => $journal ? 'posted' : 'pending_finance',
            ]);

            return $return->load(['items.product', 'items.color', 'items.unit', 'purchaseInvoice', 'treasury', 'currency', 'warehouse']);
        });

        return response()->json([
            'data' => new PurchaseReturnResource($return),
            'result' => 'Success',
            'message' => 'Purchase return created successfully',
            'status' => 200,
        ]);
    } catch (\RuntimeException $e) {
        Log::warning('Purchase return rejected', ['error' => $e->getMessage(), 'invoice_id' => $request->purchase_invoices_id]);
        return response()->json(['result' => 'Error', 'message' => $e->getMessage(), 'status' => 422], 422);
    } catch (\Throwable $e) {
        Log::error('Purchase return creation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return response()->json(['result' => 'Error', 'message' => $e->getMessage(), 'status' => 500], 500);
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
