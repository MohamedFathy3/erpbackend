<?php

namespace App\Http\Controllers;

use App\Http\Resources\CashierShiftResource;
use App\Models\Admin;
use App\Models\CashierShift;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Invoice;
use App\Models\InvoicePayment;
class CashierShiftController extends Controller
{


public function report($shiftId)
{
    try {

        /*
        |--------------------------------------------------------------------------
        | بيانات الوردية
        |--------------------------------------------------------------------------
        */

        $shift = CashierShift::with([
            'employee',
            'admin',
        ])->findOrFail($shiftId);


        /*
        |--------------------------------------------------------------------------
        | فواتير الوردية
        |--------------------------------------------------------------------------
        */

        $invoices = Invoice::with([
            'items.product',
            'payments',
            'customer',
            'cashier',
            'treasury',
        ])
            ->where('cashier_shift_id', $shift->id)
            ->orderBy('created_at', 'asc')
            ->get();


        /*
        |--------------------------------------------------------------------------
        | إجماليات الوردية
        |--------------------------------------------------------------------------
        */

        $totalSales = 0;
        $totalCost = 0;
        $totalProfit = 0;

        $totalQuantity = 0;

        $cashTotal = 0;
        $cardTotal = 0;
        $walletTotal = 0;

        $sales = [];


        /*
        |--------------------------------------------------------------------------
        | تقرير المنتجات
        |--------------------------------------------------------------------------
        |
        | هنا سنجمع كل المنتجات المباعة في الوردية.
        |
        */

        $productsReport = [];


        /*
        |--------------------------------------------------------------------------
        | الفواتير
        |--------------------------------------------------------------------------
        */

        foreach ($invoices as $invoice) {

            $invoiceSales = 0;
            $invoiceCost = 0;
            $invoiceProfit = 0;

            $invoiceQuantity = 0;

            $items = [];


            /*
            |--------------------------------------------------------------------------
            | بنود الفاتورة
            |--------------------------------------------------------------------------
            */

            foreach ($invoice->items as $item) {

                $quantity = (float) ($item->quantity ?? 0);

                /*
                |--------------------------------------------------------------------------
                | سعر البيع
                |--------------------------------------------------------------------------
                */

                $sellingPrice = (float) ($item->price ?? 0);

                $sellingTotal = $item->total !== null
                    ? (float) $item->total
                    : ($sellingPrice * $quantity);


                /*
                |--------------------------------------------------------------------------
                | تكلفة المنتج
                |--------------------------------------------------------------------------
                |
                | التكلفة الأساسية تأتي من:
                |
                | products.cost
                |
                | لأن جدول products عندك يحتوي:
                |
                | price
                | cost
                |
                */

                $unitCost = 0;

                if ($item->product) {

                    $unitCost = (float) ($item->product->cost ?? 0);

                }


                /*
                |--------------------------------------------------------------------------
                | لو كان invoice_item يحتوي على تكلفة محفوظة
                |
                | يمكن استخدامها أولاً إذا أردت تثبيت تكلفة وقت البيع.
                |--------------------------------------------------------------------------
                */

                if (
                    isset($item->unit_cost)
                    && $item->unit_cost !== null
                    && (float) $item->unit_cost > 0
                ) {

                    $unitCost = (float) $item->unit_cost;

                }


                /*
                |--------------------------------------------------------------------------
                | إجمالي تكلفة هذا المنتج
                |--------------------------------------------------------------------------
                */

                $itemTotalCost = $unitCost * $quantity;


                /*
                |--------------------------------------------------------------------------
                | الربح
                |--------------------------------------------------------------------------
                */

                $itemProfit = $sellingTotal - $itemTotalCost;


                /*
                |--------------------------------------------------------------------------
                | إجماليات الفاتورة
                |--------------------------------------------------------------------------
                */

                $invoiceSales += $sellingTotal;

                $invoiceCost += $itemTotalCost;

                $invoiceProfit += $itemProfit;

                $invoiceQuantity += $quantity;


                /*
                |--------------------------------------------------------------------------
                | إجماليات الوردية
                |--------------------------------------------------------------------------
                */

                $totalSales += $sellingTotal;

                $totalCost += $itemTotalCost;

                $totalProfit += $itemProfit;

                $totalQuantity += $quantity;


                /*
                |--------------------------------------------------------------------------
                | تقرير المنتج داخل الفاتورة
                |--------------------------------------------------------------------------
                */

                $items[] = [

                    'product_id' => $item->product_id,

                    'product_name' => $item->product_name
                        ?? $item->product?->name,

                    'color' => $item->color,

                    'size' => $item->size,

                    'quantity' => $quantity,


                    /*
                    | البيع
                    */

                    'selling_price' => $sellingPrice,

                    'selling_total' => $sellingTotal,


                    /*
                    | التكلفة
                    */

                    'unit_cost' => $unitCost,

                    'total_cost' => $itemTotalCost,


                    /*
                    | الربح
                    */

                    'profit' => $itemProfit,
                ];


                /*
                |--------------------------------------------------------------------------
                | تجميع المنتجات
                |--------------------------------------------------------------------------
                */

                $productId = $item->product_id;

                if (!isset($productsReport[$productId])) {

                    $productsReport[$productId] = [

                        'product_id' => $productId,

                        'product_name' => $item->product_name
                            ?? $item->product?->name,

                        'quantity' => 0,

                        'selling_total' => 0,

                        'total_cost' => 0,

                        'profit' => 0,

                    ];
                }


                $productsReport[$productId]['quantity'] += $quantity;

                $productsReport[$productId]['selling_total'] += $sellingTotal;

                $productsReport[$productId]['total_cost'] += $itemTotalCost;

                $productsReport[$productId]['profit'] += $itemProfit;
            }


            /*
            |--------------------------------------------------------------------------
            | طرق الدفع
            |--------------------------------------------------------------------------
            */

            $payments = [];

            foreach ($invoice->payments as $payment) {

                $amount = (float) ($payment->amount ?? 0);

                $payments[] = [

                    'method' => $payment->method,

                    'amount' => $amount,
                ];


                switch ($payment->method) {

                    case 'cash':

                        $cashTotal += $amount;

                        break;

                    case 'card':

                        $cardTotal += $amount;

                        break;

                    case 'wallet':

                        $walletTotal += $amount;

                        break;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | بيانات الفاتورة
            |--------------------------------------------------------------------------
            */

            $sales[] = [

                'id' => $invoice->id,

                'invoice_number' => $invoice->invoice_number,

                'time' => optional($invoice->created_at)
                    ->format('H:i:s'),

                'date' => optional($invoice->created_at)
                    ->format('Y-m-d H:i:s'),

                'customer' => $invoice->customer?->name,

                'items_count' => count($items),

                'total_quantity' => $invoiceQuantity,

                /*
                | تفاصيل المنتجات
                */

                'items' => $items,

                /*
                | إجماليات الفاتورة
                */

                'total_sales' => $invoiceSales,

                'total_cost' => $invoiceCost,

                'profit' => $invoiceProfit,

                /*
                | الدفع
                */

                'payments' => $payments,

                'payment_total' => array_sum(
                    array_column($payments, 'amount')
                ),

                'status' => $invoice->status,
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | تحويل تقرير المنتجات إلى Array
        |--------------------------------------------------------------------------
        */

        $productsReport = array_values($productsReport);


        /*
        |--------------------------------------------------------------------------
        | إجمالي المنتجات
        |--------------------------------------------------------------------------
        */

        $productsTotal = [

            'products_count' => count($productsReport),

            'total_quantity' => $totalQuantity,

            'total_sales' => $totalSales,

            'total_cost' => $totalCost,

            'total_profit' => $totalProfit,
        ];


        /*
        |--------------------------------------------------------------------------
        | المرتجعات
        |--------------------------------------------------------------------------
        */

        $returnsCount = 0;

        $returnsAmount = 0;

        $returnsCost = 0;


        /*
        |--------------------------------------------------------------------------
        | الصافي
        |--------------------------------------------------------------------------
        */

        $netSales = $totalSales - $returnsAmount;

        $netCost = $totalCost - $returnsCost;

        $netProfit = $netSales - $netCost;


        /*
        |--------------------------------------------------------------------------
        | التسوية النقدية
        |--------------------------------------------------------------------------
        */

        $openingBalance = (float) (
            $shift->opening_balance ?? 0
        );


        $expectedCash =
            $openingBalance
            + $cashTotal
            - $returnsAmount;


        $actualAmount = $shift->actual_amount !== null
            ? (float) $shift->actual_amount
            : null;


        $difference = $actualAmount !== null
            ? $actualAmount - $expectedCash
            : null;


        /*
        |--------------------------------------------------------------------------
        | اسم الكاشير
        |--------------------------------------------------------------------------
        */

        $cashierName = null;

        if ($shift->employee) {

            $cashierName = $shift->employee->name;

        } elseif ($shift->admin) {

            $cashierName = $shift->admin->name;
        }


        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'status' => true,

            'data' => [

                /*
                |--------------------------------------------------------------------------
                | الوردية
                |--------------------------------------------------------------------------
                */

                'shift' => [

                    'id' => $shift->id,

                    'cashier' => $cashierName,

                    'branch_id' => $shift->employee?->branch_id,

                    'opened_at' => $shift->opened_at,

                    'closed_at' => $shift->closed_at,

                    'opening_balance' => $openingBalance,

                    'expected_amount' => $expectedCash,

                    'actual_amount' => $actualAmount,

                    'difference' => $difference,

                    'status' => $shift->status,
                ],


                /*
                |--------------------------------------------------------------------------
                | المبيعات
                |--------------------------------------------------------------------------
                */

                'sales' => [

                    'invoices_count' => $invoices->count(),

                    'total_sales' => $totalSales,

                    'total_cost' => $totalCost,

                    'total_profit' => $totalProfit,

                    'total_quantity' => $totalQuantity,

                    'invoices' => $sales,
                ],


                /*
                |--------------------------------------------------------------------------
                | تقرير المنتجات
                |--------------------------------------------------------------------------
                |
                | كل المنتجات التي تم بيعها في الوردية.
                |
                */

                'products' => [

                    'items' => $productsReport,

                    'totals' => $productsTotal,
                ],


                /*
                |--------------------------------------------------------------------------
                | التحصيل
                |--------------------------------------------------------------------------
                */

                'collections' => [

                    'cash' => $cashTotal,

                    'card' => $cardTotal,

                    'wallet' => $walletTotal,

                    'total' =>
                        $cashTotal
                        + $cardTotal
                        + $walletTotal,
                ],


                /*
                |--------------------------------------------------------------------------
                | المرتجعات
                |--------------------------------------------------------------------------
                */

                'returns' => [

                    'count' => $returnsCount,

                    'total_amount' => $returnsAmount,

                    'total_cost' => $returnsCost,
                ],


                /*
                |--------------------------------------------------------------------------
                | الصافي
                |--------------------------------------------------------------------------
                */

                'net' => [

                    'sales' => $netSales,

                    'cost' => $netCost,

                    'profit' => $netProfit,
                ],


                /*
                |--------------------------------------------------------------------------
                | التسوية
                |--------------------------------------------------------------------------
                */

                'reconciliation' => [

                    'opening_balance' => $openingBalance,

                    'cash_sales' => $cashTotal,

                    'cash_returns' => $returnsAmount,

                    'expected_amount' => $expectedCash,

                    'actual_amount' => $actualAmount,

                    'difference' => $difference,
                ],
            ],
        ]);

    } catch (\Exception $e) {

        return response()->json([

            'status' => false,

            'message' => 'حدث خطأ أثناء إنشاء تقرير الوردية',

            'error' => $e->getMessage(),

        ], 500);
    }
}


      public function openShift(Request $request)
    {
        $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'notes'           => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $shiftData = [
                'opening_balance' => $request->opening_balance,
                'notes'           => $request->notes,
                'opened_at'       => now(),
                'status'          => 'open',
            ];

        $user = $request->user();

        if ($user instanceof Admin) {
            $shiftData['admin_id'] = $user->id;
        } elseif ($user instanceof Employee) {
            $shiftData['employee_id'] = $user->id;
        } else {
            throw new \Exception('نوع المستخدم غير معروف');
        }


            $shift = CashierShift::create($shiftData);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'تم فتح الورديه بنجاح',
                'data'    => new CashierShiftResource($shift)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء فتح الورديه',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // اغلاق وردية
  public function closeShift(Request $request)
    {
        $request->validate([
            'actual_amount' => 'required|numeric|min:0',
            'notes'         => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $user = auth()->user();

            // جلب آخر وردية مفتوحة للشخص الحالي
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

            if (!$shift) {
                return response()->json([
                    'status' => false,
                    'message' => 'لا توجد وردية مفتوحة حالياً'
                ], 404);
            }

            $expected = ($shift->cash_sales + $shift->card_sales + $shift->wallet_sales - $shift->returns_amount);

            $shift->update([
                'closing_balance' => $request->actual_amount,
                'actual_amount'   => $request->actual_amount,
                'expected_amount' => $expected,
                'difference'      => $request->actual_amount - $expected,
                'status'          => 'closed',
                'closed_at'       => now(),
                'notes'           => $request->notes,
            ]);
            \App\Models\InvoiceTransferRequest::where('cashier_shift_id', $shift->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'note' => 'تم الإلغاء تلقائيًا لإغلاق الوردية']);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'تم إغلاق الورديه بنجاح',
                'data'    => new CashierShiftResource($shift->fresh())
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء إغلاق الورديه',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // عرض كل الورديات
    public function index(Request $request)
    {
        $shifts = CashierShift::with(['employee', 'admin'])
            ->when($request->filled('branch_id'), function ($query) use ($request) {
                $query->whereHas('employee', function ($q) use ($request) {
                    $q->where('branch_id', $request->branch_id);
                });
            })
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'data'   => CashierShiftResource::collection($shifts)
        ]);
    }

    // عرض وردية واحدة
    public function show(cashierShift $shift)
    {
        $shift->load(['employee','admin']);
        return response()->json([
            'status' => true,
            'data'   => new CashierShiftResource($shift)
        ]);
    }


   public function getCurrentShift()
    {
        $user = auth()->user();

        $query = CashierShift::query()->where('status', 'open')
            ->where(function ($q) use ($user) {
                if ($user instanceof Admin) {
                    $q->where('admin_id', $user->id);
                } elseif ($user instanceof Employee) {
                    $q->where('employee_id', $user->id);
                }
            });

        $shift = $query->latest('opened_at')->first();

        if (!$shift) {
            return response()->json([
                'status' => false,
                'message' => 'لا توجد وردية مفتوحة حالياً'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'آخر وردية مفتوحة',
            'data' => $shift
        ]);
    }

}
