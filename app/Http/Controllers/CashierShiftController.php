<?php

namespace App\Http\Controllers;

use App\Http\Resources\CashierShiftResource;
use App\Models\Admin;
use App\Models\CashierShift;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\ReturnInvoice;
use App\Models\SalesInvoiceReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CashierShiftController extends Controller
{




    public function report(Request $request, $shiftId)
    {
        try {
            $shift = CashierShift::with(['employee', 'admin'])->findOrFail($shiftId);

            $includeInvoices = $request->boolean('include_invoices', true);
            $includeReturns  = $request->boolean('include_returns', true);
            $includeProducts = $request->boolean('include_products', true);

            $startAt = $shift->opened_at ? \Carbon\Carbon::parse($shift->opened_at) : now();
            $endAt   = $shift->closed_at ? \Carbon\Carbon::parse($shift->closed_at) : now();

            // ============================================================
            // Helpers (كل الحسابات بتتقرب لرقمين عشريين على مستوى السطر
            // عشان مجموع الأسطر = الإجمالي بالظبط)
            // ============================================================
            $money   = fn ($v) => round((float) ($v ?? 0), 2);
            $qtyFmt  = fn ($v) => round((float) ($v ?? 0), 3);
            $percent = fn ($part, $base) => (float) $base > 0
                ? round(((float) $part / (float) $base) * 100, 2)
                : 0;

            // تصنيف طريقة الدفع / الاسترداد
            $classify = function ($method, string $emptyAs = 'other') {
                $m = mb_strtolower(trim((string) $method));

                if ($m === '') {
                    return $emptyAs;
                }
                if (in_array($m, ['cash', 'نقدي', 'نقدا', 'نقداً', 'كاش'], true)) {
                    return 'cash';
                }
                if (in_array($m, ['card', 'visa', 'بطاقة', 'بطاقه', 'فيزا'], true)) {
                    return 'card';
                }
                if (in_array($m, ['wallet', 'محفظة', 'محفظه', 'محفظة إلكترونية', 'رصيد'], true)) {
                    return 'wallet';
                }
                return 'other';
            };

            // تكلفة الوحدة: الأولوية للتكلفة المسجلة وقت البيع (snapshot)،
            // ولو مش موجودة بنرجع لتكلفة المنتج الحالية. (نفس المنطق للمبيعات والمرتجعات)
            $unitCostOf = function ($item) {
                $snapshot = (float) ($item->unit_cost ?? $item->cost_price ?? 0);
                if ($snapshot > 0) {
                    return $snapshot;
                }
                return (float) ($item->product?->cost ?? 0);
            };

            // إجمالي سطر البيع
            $lineTotalOf = function ($item, float $quantity) use ($money) {
                return $item->total !== null
                    ? $money($item->total)
                    : $money((float) ($item->price ?? 0) * $quantity);
            };

            $productNameOf = fn ($item) => $item->product_name
                ?? $item->product?->name
                ?? 'غير محدد';

            // مفتاح تجميع المنتجات (لو مفيش product_id بنجمع بالاسم بدل ما نخلط كل الخدمات في "unknown")
            $productKeyOf = fn ($item, string $name) => $item->product_id
                ? 'p' . $item->product_id
                : 'n' . md5($name);

            // ----- مندوب المبيعات (بيتعرف تلقائياً على اسم العلاقة/العمود الموجود عندك) -----
            $repRelation = null;
            foreach (['salesRepresentative', 'salesRep', 'representative', 'salesman', 'salesperson'] as $rel) {
                if (method_exists(Invoice::class, $rel)) {
                    $repRelation = $rel;
                    break;
                }
            }
            $repColumns = ['sales_representative_id', 'sales_rep_id', 'representative_id', 'salesman_id'];

            $repOf = function ($invoice) use ($repRelation, $repColumns) {
                if (!$invoice) {
                    return ['id' => null, 'name' => null];
                }
                $id = null;
                foreach ($repColumns as $col) {
                    if (!empty($invoice->{$col})) {
                        $id = $invoice->{$col};
                        break;
                    }
                }
                $relObj = $repRelation ? $invoice->{$repRelation} : null;
                return [
                    'id'   => $relObj?->id ?? $id,
                    'name' => $relObj?->name,
                ];
            };

            // تجميع حسب العميل / المندوب
            $customersReport = [];
            $repsReport      = [];

            $ensureGroup = function (array &$bag, string $key, $id, $name) {
                if (!isset($bag[$key])) {
                    $bag[$key] = [
                        'id'             => $id,
                        'name'           => $name,
                        'invoices_count' => 0,
                        'sales'          => 0.0,
                        'cost'           => 0.0,
                        'profit'         => 0.0,
                        'returns_count'  => 0,
                        'returns_amount' => 0.0,
                        'returns_cost'   => 0.0,
                        'refunded'       => 0.0,
                    ];
                }
            };

            $newProductRow = fn ($productId, string $name) => [
                'product_id'        => $productId,
                'product_name'      => $name,
                'quantity'          => 0.0,
                'selling_total'     => 0.0,
                'total_cost'        => 0.0,
                'profit'            => 0.0,
                'returned_quantity' => 0.0,
                'returned_total'    => 0.0,
                'returned_cost'     => 0.0,
            ];

            // ============================================================
            // 1. الفواتير (POS)
            // ============================================================
            // حالات الفواتير اللي مش بتتحسب مبيعات (عدّلها حسب النظام عندك)
            $excludedInvoiceStatuses = ['cancelled', 'canceled', 'void', 'voided', 'draft', 'ملغي', 'ملغاة', 'ملغية'];

            $invoiceTable = (new Invoice())->getTable();
            $hasShiftColumn = \Illuminate\Support\Facades\Schema::hasColumn($invoiceTable, 'shift_id');

            $invoiceWith = ['items.product', 'payments', 'customer'];
            if ($repRelation) {
                $invoiceWith[] = $repRelation;
            }
            $invoiceQuery = Invoice::with($invoiceWith);

            if ($hasShiftColumn) {
                // الفواتير المربوطة بالوردية دي، + فواتير قديمة مالهاش shift_id داخل نطاق الوقت
                $invoiceQuery->where(function ($q) use ($shift, $startAt, $endAt) {
                    $q->where('shift_id', $shift->id)
                      ->orWhere(function ($x) use ($startAt, $endAt) {
                          $x->whereNull('shift_id')
                            ->whereBetween('created_at', [$startAt, $endAt]);
                      });
                });
            } else {
                $invoiceQuery->whereBetween('created_at', [$startAt, $endAt]);
            }

            $invoices = $invoiceQuery
                ->where(function ($q) use ($excludedInvoiceStatuses) {
                    $q->whereNull('status')
                      ->orWhereNotIn('status', $excludedInvoiceStatuses);
                })
                ->orderBy('created_at', 'asc')
                ->get();

            // ============================================================
            // 2. تجميع المبيعات
            // ============================================================
            $totalSales    = 0.0;
            $totalCost     = 0.0;
            $totalQuantity = 0.0;

            $collections = ['cash' => 0.0, 'card' => 0.0, 'wallet' => 0.0, 'other' => 0.0];

            $sales          = [];
            $productsReport = [];

            $missingCostCount = 0;
            $missingCostNames = [];

            foreach ($invoices as $invoice) {
                $invoiceSales    = 0.0;
                $invoiceCost     = 0.0;
                $invoiceQuantity = 0.0;
                $items           = [];
                $payments        = [];

                foreach ($invoice->items as $item) {
                    $quantity     = $qtyFmt($item->quantity);
                    $sellingPrice = $money($item->price);
                    $sellingTotal = $lineTotalOf($item, $quantity);

                    $unitCost      = $money($unitCostOf($item));
                    $itemTotalCost = $money($unitCost * $quantity);
                    $itemProfit    = $money($sellingTotal - $itemTotalCost);

                    $productName = $productNameOf($item);

                    if ($unitCost <= 0) {
                        $missingCostCount++;
                        $missingCostNames[$productName] = true;
                    }

                    $invoiceSales    += $sellingTotal;
                    $invoiceCost     += $itemTotalCost;
                    $invoiceQuantity += $quantity;

                    $totalSales    += $sellingTotal;
                    $totalCost     += $itemTotalCost;
                    $totalQuantity += $quantity;

                    $items[] = [
                        'id'            => $item->id,
                        'product_id'    => $item->product_id,
                        'product_name'  => $productName,
                        'color'         => $item->color ?? null,
                        'size'          => $item->size ?? null,
                        'quantity'      => $quantity,
                        'selling_price' => $sellingPrice,
                        'selling_total' => $sellingTotal,
                        'unit_cost'     => $unitCost,
                        'total_cost'    => $itemTotalCost,
                        'profit'        => $itemProfit,
                        'cost_missing'  => $unitCost <= 0,
                    ];

                    $key = $productKeyOf($item, $productName);
                    if (!isset($productsReport[$key])) {
                        $productsReport[$key] = $newProductRow($item->product_id, $productName);
                    }

                    $productsReport[$key]['quantity']      += $quantity;
                    $productsReport[$key]['selling_total'] += $sellingTotal;
                    $productsReport[$key]['total_cost']    += $itemTotalCost;
                    $productsReport[$key]['profit']        += $itemProfit;
                }

                foreach ($invoice->payments as $payment) {
                    $amount = $money($payment->amount);
                    $bucket = $classify($payment->method);

                    $collections[$bucket] += $amount;

                    $payments[] = [
                        'method' => $payment->method,
                        'amount' => $amount,
                    ];
                }

                $invoiceSales = $money($invoiceSales);
                $invoiceCost  = $money($invoiceCost);

                // تجميع حسب العميل والمندوب
                $cid   = $invoice->customer?->id ?? ($invoice->customer_id ?? null);
                $ck    = $cid ? 'c' . $cid : 'walkin';
                $ensureGroup($customersReport, $ck, $cid, $invoice->customer?->name);
                $customersReport[$ck]['invoices_count']++;
                $customersReport[$ck]['sales'] += $invoiceSales;
                $customersReport[$ck]['cost']  += $invoiceCost;

                $rep = $repOf($invoice);
                $rk  = $rep['id'] ? 'r' . $rep['id'] : ($rep['name'] ? 'n' . md5($rep['name']) : 'none');
                $ensureGroup($repsReport, $rk, $rep['id'], $rep['name']);
                $repsReport[$rk]['invoices_count']++;
                $repsReport[$rk]['sales'] += $invoiceSales;
                $repsReport[$rk]['cost']  += $invoiceCost;

                $sales[] = [
                    'id'             => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'date'           => optional($invoice->created_at)->format('Y-m-d H:i:s'),
                    'time'           => optional($invoice->created_at)->format('H:i:s'),
                    'customer'       => $invoice->customer?->name,
                    'customer_id'    => $invoice->customer?->id ?? ($invoice->customer_id ?? null),
                    'customer_phone' => $invoice->customer?->phone,
                    'sales_rep'      => $repOf($invoice)['name'],
                    'sales_rep_id'   => $repOf($invoice)['id'],
                    'items_count'    => count($items),
                    'total_quantity' => $qtyFmt($invoiceQuantity),
                    'items'          => $items,
                    'total_sales'    => $invoiceSales,
                    'total_cost'     => $invoiceCost,
                    'profit'         => $money($invoiceSales - $invoiceCost),
                    'payments'       => $payments,
                    'payment_total'  => $money(array_sum(array_column($payments, 'amount'))),
                    'status'         => $invoice->status,
                ];
            }

            $totalSales    = $money($totalSales);
            $totalCost     = $money($totalCost);
            $totalQuantity = $qtyFmt($totalQuantity);

            foreach ($collections as $k => $v) {
                $collections[$k] = $money($v);
            }

            // ============================================================
            // 3. المرتجعات (POS + Sales)
            // ============================================================
            $shiftInvoiceIds = $invoices->pluck('id')->toArray();
            $salesReturnTable = (new SalesInvoiceReturn())->getTable();
            $hasSalesReturnShiftColumn = \Illuminate\Support\Facades\Schema::hasColumn($salesReturnTable, 'shift_id');

            $posReturns = ReturnInvoice::with([
                    'invoice.customer',
                    'invoice.treasury',
                    'items.product',
                ])
                ->where('shift_id', $shift->id)
                ->whereBetween('created_at', [$startAt, $endAt])
                ->where(function ($q) {
                    $q->whereNull('workflow_status')
                      ->orWhere('workflow_status', '!=', 'cancelled');
                })
                ->orderBy('created_at', 'asc')
                ->get();

            $salesReturns = SalesInvoiceReturn::with([
                    'invoice.customer',
                    'items.product',
                    'treasury',
                ])
                ->where(function ($query) use ($shift, $shiftInvoiceIds, $hasSalesReturnShiftColumn) {
                    if ($hasSalesReturnShiftColumn) {
                        $query->where('shift_id', $shift->id);
                    } elseif (empty($shiftInvoiceIds)) {
                        $query->whereRaw('1 = 0');
                    }
                    if (!empty($shiftInvoiceIds)) {
                        $hasSalesReturnShiftColumn
                            ? $query->orWhereIn('sales_invoice_id', $shiftInvoiceIds)
                            : $query->whereIn('sales_invoice_id', $shiftInvoiceIds);
                    }
                })
                ->whereBetween('created_at', [$startAt, $endAt])
                ->where(function ($q) {
                    $q->whereNull('workflow_status')
                      ->orWhere('workflow_status', '!=', 'cancelled');
                })
                ->orderBy('created_at', 'asc')
                ->get()
                ->unique('id');

            if ($repRelation) {
                $posReturns->loadMissing('invoice.' . $repRelation);
            }

            $returns = $posReturns->concat($salesReturns)->values();

            $returnsCount    = $returns->count();
            $returnsAmount   = 0.0;   // قيمة بنود المرتجعات (نفس أساس حساب المبيعات)
            $returnsCost     = 0.0;
            $returnsQuantity = 0.0;
            $refunds         = ['cash' => 0.0, 'card' => 0.0, 'wallet' => 0.0, 'other' => 0.0];
            $returnsBySource = ['pos' => 0, 'sales' => 0];

            $returnInvoices = [];

            foreach ($returns as $return) {
                $isPos = $return instanceof ReturnInvoice;

                $documentAmount = $money($return->total_amount);

                if ($isPos) {
                    $refundedAmount = $money($return->refunded_amount ?? $documentAmount);
                    $returnMethod   = $return->refund_method;
                    $note           = $return->reason;
                    $returnsBySource['pos']++;
                } else {
                    $refundedAmount = $documentAmount;
                    $returnMethod   = $return->return_method;
                    $note           = $return->note;
                    $returnsBySource['sales']++;
                }

                $invoiceRef = $return->invoice;
                $status     = $return->workflow_status;

                $returnItemsTotal = 0.0;
                $returnCost       = 0.0;
                $returnQuantity   = 0.0;
                $returnItems      = [];

                foreach ($return->items as $item) {
                    $quantity  = $qtyFmt($item->quantity);
                    $price     = $money($item->price);
                    $lineTotal = $lineTotalOf($item, $quantity);

                    $unitCost = $money($unitCostOf($item));
                    $lineCost = $money($unitCost * $quantity);

                    $productName = $productNameOf($item);

                    if ($unitCost <= 0) {
                        $missingCostCount++;
                        $missingCostNames[$productName] = true;
                    }

                    $returnItemsTotal += $lineTotal;
                    $returnCost       += $lineCost;
                    $returnQuantity   += $quantity;

                    // تأثير المرتجع على تقرير المنتجات
                    $key = $productKeyOf($item, $productName);
                    if (!isset($productsReport[$key])) {
                        $productsReport[$key] = $newProductRow($item->product_id, $productName);
                    }
                    $productsReport[$key]['returned_quantity'] += $quantity;
                    $productsReport[$key]['returned_total']    += $lineTotal;
                    $productsReport[$key]['returned_cost']     += $lineCost;

                    $returnItems[] = [
                        'id'              => $item->id,
                        'product_id'      => $item->product_id,
                        'product_name'    => $productName,
                        'quantity'        => $quantity,
                        'selling_price'   => $price,
                        'selling_total'   => $lineTotal,
                        'unit_cost'       => $unitCost,
                        'total_cost'      => $lineCost,
                        'profit_reversed' => $money($lineTotal - $lineCost),
                        'reason'          => $note,
                        'color'           => $item->color ?? null,
                        'size'            => $item->size ?? null,
                        'cost_missing'    => $unitCost <= 0,
                    ];
                }

                $returnItemsTotal = $money($returnItemsTotal);
                $returnCost       = $money($returnCost);

                // قيمة المرتجع = مجموع البنود (عشان تتطابق مع طريقة حساب المبيعات)،
                // ولو مفيش بنود بنرجع لإجمالي المستند.
                $returnAmount = $returnItemsTotal > 0 ? $returnItemsTotal : $documentAmount;

                $returnsAmount   += $returnAmount;
                $returnsCost     += $returnCost;
                $returnsQuantity += $returnQuantity;

                // لو طريقة الرد فاضية بنعتبرها نقدي (الاسترداد من درج الكاشير)
                $refunds[$classify($returnMethod, 'cash')] += $refundedAmount;

                // تجميع المرتجعات حسب العميل والمندوب
                $rcid = $invoiceRef?->customer?->id ?? ($return->customer_id ?? null);
                $rck  = $rcid ? 'c' . $rcid : 'walkin';
                $ensureGroup(
                    $customersReport,
                    $rck,
                    $rcid,
                    $invoiceRef?->customer?->name ?? ($isPos ? null : $return->customer?->name)
                );
                $customersReport[$rck]['returns_count']++;
                $customersReport[$rck]['returns_amount'] += $returnAmount;
                $customersReport[$rck]['returns_cost']   += $returnCost;
                $customersReport[$rck]['refunded']       += $refundedAmount;

                $rrep = $repOf($invoiceRef);
                $rrk  = $rrep['id'] ? 'r' . $rrep['id'] : ($rrep['name'] ? 'n' . md5($rrep['name']) : 'none');
                $ensureGroup($repsReport, $rrk, $rrep['id'], $rrep['name']);
                $repsReport[$rrk]['returns_count']++;
                $repsReport[$rrk]['returns_amount'] += $returnAmount;
                $repsReport[$rrk]['returns_cost']   += $returnCost;
                $repsReport[$rrk]['refunded']       += $refundedAmount;

                $returnInvoices[] = [
                    'id'              => $return->id,
                    'return_number'   => $return->return_number,
                    'date'            => optional($return->created_at)->format('Y-m-d H:i:s'),
                    'source'          => $isPos ? 'pos' : 'sales',
                    'sales_invoice_id'=> $isPos ? $return->invoice_id : $return->sales_invoice_id,
                    'invoice_number'  => $invoiceRef?->invoice_number,
                    'invoice_id'      => $invoiceRef?->id,
                    'customer'        => $invoiceRef?->customer?->name
                                         ?? ($isPos ? null : $return->customer?->name),
                    'customer_id'     => $invoiceRef?->customer?->id ?? ($return->customer_id ?? null),
                    'sales_rep'       => $repOf($invoiceRef)['name'],
                    'return_method'   => $returnMethod,
                    'is_direct'       => $isPos ? false : (bool) ($return->is_direct ?? false),
                    'status'          => $status,
                    'note'            => $note,
                    'treasury_id'     => $isPos ? $invoiceRef?->treasury_id : $return->treasury_id,
                    'treasury_name'   => $isPos ? $invoiceRef?->treasury?->name : $return->treasury?->name,
                    'items_count'     => count($returnItems),
                    'total_quantity'  => $qtyFmt($returnQuantity),
                    'items'           => $returnItems,
                    'document_amount' => $documentAmount,
                    'total_amount'    => $returnAmount,
                    'refunded_amount' => $refundedAmount,
                    'total_cost'      => $returnCost,
                    'profit_reversed' => $money($returnAmount - $returnCost),
                ];
            }

            $returnsAmount   = $money($returnsAmount);
            $returnsCost     = $money($returnsCost);
            $returnsQuantity = $qtyFmt($returnsQuantity);

            foreach ($refunds as $k => $v) {
                $refunds[$k] = $money($v);
            }

            // ============================================================
            // 4. تقرير المنتجات (بيع + مرتجع + صافي)
            // ============================================================
            $productsReport = array_map(function ($p) use ($money, $qtyFmt, $percent) {
                $p['quantity']          = $qtyFmt($p['quantity']);
                $p['selling_total']     = $money($p['selling_total']);
                $p['total_cost']        = $money($p['total_cost']);
                $p['profit']            = $money($p['selling_total'] - $p['total_cost']);
                $p['returned_quantity'] = $qtyFmt($p['returned_quantity']);
                $p['returned_total']    = $money($p['returned_total']);
                $p['returned_cost']     = $money($p['returned_cost']);

                $p['profit_margin'] = $percent($p['profit'], $p['selling_total']);

                $p['net_quantity'] = $qtyFmt($p['quantity'] - $p['returned_quantity']);
                $p['net_sales']    = $money($p['selling_total'] - $p['returned_total']);
                $p['net_cost']     = $money($p['total_cost'] - $p['returned_cost']);
                $p['net_profit']   = $money($p['net_sales'] - $p['net_cost']);

                return $p;
            }, array_values($productsReport));

            usort($productsReport, fn ($a, $b) => $b['profit'] <=> $a['profit']);

            // ============================================================
            // 5. الصافي والتسوية
            // ============================================================
            $finalizeGroups = function (array $rows) use ($money) {
                $rows = array_map(function ($r) use ($money) {
                    foreach (['sales', 'cost', 'returns_amount', 'returns_cost', 'refunded'] as $f) {
                        $r[$f] = $money($r[$f]);
                    }
                    $r['profit']     = $money($r['sales'] - $r['cost']);
                    $r['net_sales']  = $money($r['sales'] - $r['returns_amount']);
                    $r['net_profit'] = $money($r['profit'] - ($r['returns_amount'] - $r['returns_cost']));
                    return $r;
                }, array_values($rows));
                usort($rows, fn ($a, $b) => $b['net_sales'] <=> $a['net_sales']);
                return $rows;
            };
            $customersReport = $finalizeGroups($customersReport);
            $repsReport      = $finalizeGroups($repsReport);

            $grossProfit   = $money($totalSales - $totalCost);
            $returnsProfit = $money($returnsAmount - $returnsCost);
            $netSales      = $money($totalSales - $returnsAmount);
            $netCost       = $money($totalCost - $returnsCost);
            $netProfit     = $money($netSales - $netCost); // = grossProfit - returnsProfit

            $totalRefunded     = $money(array_sum($refunds));
            $collectionsTotal  = $money(array_sum($collections));

            $openingBalance = $money($shift->opening_balance);
            $expectedCash   = $money($openingBalance + $collections['cash'] - $refunds['cash']);

            $actualAmount = $shift->actual_amount !== null
                ? $money($shift->actual_amount)
                : null;

            $difference = $actualAmount !== null
                ? $money($actualAmount - $expectedCash)
                : null;

            if ($difference === null) {
                $reconciliationStatus = 'pending';
            } elseif (abs($difference) < 0.01) {
                $reconciliationStatus = 'balanced';
            } elseif ($difference > 0) {
                $reconciliationStatus = 'over';
            } else {
                $reconciliationStatus = 'short';
            }

            $cashierName = $shift->employee?->name ?? $shift->admin?->name;

            // اسم الفرع (من علاقة الموظف لو موجودة، وإلا من جدول branches)
            $branchId   = $shift->employee?->branch_id ?? ($shift->branch_id ?? null);
            $branchName = null;

            if ($shift->employee && method_exists($shift->employee, 'branch')) {
                $branchName = $shift->employee->branch?->name;
            }

            if (!$branchName && $branchId && \Illuminate\Support\Facades\Schema::hasTable('branches')) {
                foreach (['name', 'name_ar', 'branch_name', 'title'] as $col) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('branches', $col)) {
                        $branchName = \Illuminate\Support\Facades\DB::table('branches')
                            ->where('id', $branchId)
                            ->value($col);
                        break;
                    }
                }
            }

            $durationMinutes = $shift->opened_at
                ? (int) round(abs($endAt->getTimestamp() - $startAt->getTimestamp()) / 60)
                : 0;

            // ============================================================
            // 6. بناء الـ Response
            // ============================================================
            $data = [
                'shift' => [
                    'id'               => $shift->id,
                    'cashier'          => $cashierName,
                    'cashier_employee' => [
                        'id'    => $shift->employee?->id,
                        'name'  => $shift->employee?->name,
                        'phone' => $shift->employee?->phone,
                    ],
                    'opened_by_admin'  => [
                        'id'   => $shift->admin?->id,
                        'name' => $shift->admin?->name,
                    ],
                    'branch_id'        => $branchId,
                    'branch_name'      => $branchName,
                    'opened_at'        => $shift->opened_at,
                    'closed_at'        => $shift->closed_at,
                    'duration_minutes' => $durationMinutes,
                    'status'           => $shift->status,
                ],

                'summary' => [
                    'sales' => [
                        'invoices_count' => count($sales),
                        'total_quantity' => $totalQuantity,
                        'gross_sales'    => $totalSales,
                        'total_cost'     => $totalCost,
                        'gross_profit'   => $grossProfit,
                        'profit_margin'  => $percent($grossProfit, $totalSales),
                    ],

                    'returns' => [
                        'count'           => $returnsCount,
                        'total_quantity'  => $returnsQuantity,
                        'total_amount'    => $returnsAmount,
                        'total_refunded'  => $totalRefunded,
                        'total_cost'      => $returnsCost,
                        'profit_reversed' => $returnsProfit,
                        'profit_margin'   => $percent($returnsProfit, $returnsAmount),
                    ],

                    'net' => [
                        'sales'         => $netSales,
                        'cost'          => $netCost,
                        'profit'        => $netProfit,
                        'profit_margin' => $percent($netProfit, $netSales),
                    ],
                ],

                'collections' => [
                    'cash'   => $collections['cash'],
                    'card'   => $collections['card'],
                    'wallet' => $collections['wallet'],
                    'other'  => $collections['other'],
                    'total'  => $collectionsTotal,
                ],

                'returns_breakdown' => [
                    'cash'      => $refunds['cash'],
                    'card'      => $refunds['card'],
                    'wallet'    => $refunds['wallet'],
                    'other'     => $refunds['other'],
                    'total'     => $totalRefunded,
                    'by_source' => $returnsBySource,
                ],

                'reconciliation' => [
                    'opening_balance' => $openingBalance,
                    'cash_sales'      => $collections['cash'],
                    'cash_returns'    => $refunds['cash'],
                    'expected_amount' => $expectedCash,
                    'actual_amount'   => $actualAmount,
                    'difference'      => $difference,
                    'status'          => $reconciliationStatus,
                ],

                // فحوصات سلامة الأرقام
                'checks' => [
                    'items_without_cost_count' => $missingCostCount,
                    'items_without_cost_names' => array_slice(array_keys($missingCostNames), 0, 10),
                    // فرق بين اللي اتحصّل واللي اتباع (ممكن يكون طبيعي لو فيه آجل/ضريبة/خصم على الفاتورة)
                    'collections_minus_sales'  => $money($collectionsTotal - $totalSales),
                ],

                'customers'             => $customersReport,
                'sales_representatives' => $repsReport,

                'top_products' => array_slice($productsReport, 0, 10),
            ];

            if ($includeInvoices) {
                $data['sales_invoices'] = $sales;
            }

            if ($includeReturns) {
                $data['returns_details'] = $returnInvoices;
            }

            if ($includeProducts) {
                $data['products'] = [
                    'items'  => $productsReport,
                    'totals' => [
                        'products_count'    => count($productsReport),
                        'total_quantity'    => $totalQuantity,
                        'total_sales'       => $totalSales,
                        'total_cost'        => $totalCost,
                        'total_profit'      => $grossProfit,
                        'returned_quantity' => $returnsQuantity,
                        'returned_total'    => $returnsAmount,
                        'returned_cost'     => $returnsCost,
                        'net_profit'        => $netProfit,
                    ],
                ];
            }

            return response()->json([
                'status' => true,
                'data'   => $data,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Cashier shift report failed', [
                'shift_id' => $shiftId,
                'error'    => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء إنشاء تقرير الوردية',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ============================================================
    // فتح وردية
    // ============================================================
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
                'data'    => new CashierShiftResource($shift),
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

    // ============================================================
    // إغلاق وردية
    // ============================================================
    public function closeShift(Request $request)
    {
        $request->validate([
            'actual_amount' => 'required|numeric|min:0',
            'notes'         => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
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

            if (!$shift) {
                return response()->json([
                    'status'  => false,
                    'message' => 'لا توجد وردية مفتوحة حالياً',
                ], 404);
            }

            $expected = (
                $shift->cash_sales
                + $shift->card_sales
                + $shift->wallet_sales
                - $shift->returns_amount
            );

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
                ->update([
                    'status' => 'cancelled',
                    'note'   => 'تم الإلغاء تلقائيًا لإغلاق الوردية',
                ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'تم إغلاق الورديه بنجاح',
                'data'    => new CashierShiftResource($shift->fresh()),
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

    // ============================================================
    // عرض كل الورديات
    // ============================================================
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
            'data'   => CashierShiftResource::collection($shifts),
        ]);
    }

    // ============================================================
    // عرض وردية واحدة
    // ============================================================
    public function show(CashierShift $shift)
    {
        $shift->load(['employee', 'admin']);

        return response()->json([
            'status' => true,
            'data'   => new CashierShiftResource($shift),
        ]);
    }

    // ============================================================
    // جلب الوردية المفتوحة حالياً
    // ============================================================
    public function getCurrentShift()
    {
        $user = auth()->user();

        $query = CashierShift::query()
            ->where('status', 'open')
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
                'status'  => false,
                'message' => 'لا توجد وردية مفتوحة حالياً',
            ], 404);
        }

        return response()->json([
            'status'  => true,
            'message' => 'آخر وردية مفتوحة',
            'data'    => $shift,
        ]);
    }
}
