<?php

namespace App\Http\Controllers;

use App\Http\Resources\CashierShiftResource;
use App\Models\Admin;
use App\Models\CashierShift;
use App\Models\Employee;
use App\Models\Finance;
use App\Models\Invoice;
use App\Models\ReturnInvoice;
use App\Models\SalesInvoiceReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CashierShiftController extends Controller
{
    /**
     * ✅ حساب المبلغ المتوقع الموحّد
     *    النقدي المتوقع = رصيد البداية + مبيعات النقدي - مرتجعات النقدي - مصروفات الوردية
     */
    private function calculateExpectedAmount(CashierShift $shift): float
    {
        return round(
            (float) $shift->opening_balance
            + (float) $shift->cash_sales

            - (float) $shift->returns_amount
            - (float) Finance::query()->withoutGlobalScope('branch')->where('cashier_shift_id', $shift->id)->where('is_tracking_only', false)->sum('amount'),
            2
        );
    }

    /** Gross sold-item totals by type; returns are reported separately. */
    private function calculateShiftItemSalesByType(CashierShift $shift): array
    {
        $totals = ['accessories_sales' => 0.0, 'services_sales' => 0.0];
        $startAt = $shift->opened_at ? \Carbon\Carbon::parse($shift->opened_at) : now();
        $endAt = $shift->closed_at ? \Carbon\Carbon::parse($shift->closed_at) : now();
        $excludedStatuses = ['cancelled', 'canceled', 'void', 'voided', 'draft', 'ملغي', 'ملغاة', 'ملغية'];
        $invoiceTable = (new Invoice())->getTable();
        $hasCashierShiftId = Schema::hasColumn($invoiceTable, 'cashier_shift_id');
        $hasShiftId = Schema::hasColumn($invoiceTable, 'shift_id');
        $invoiceQuery = Invoice::query();

        if ($hasCashierShiftId || $hasShiftId) {
            $shiftColumn = $hasCashierShiftId ? 'cashier_shift_id' : 'shift_id';
            $invoiceQuery->where(function ($query) use ($shift, $shiftColumn, $startAt, $endAt) {
                $query->where($shiftColumn, $shift->id)
                    ->orWhere(function ($fallback) use ($startAt, $endAt, $shiftColumn) {
                        $fallback->whereNull($shiftColumn)
                            ->whereBetween('created_at', [$startAt, $endAt]);
                    });
            });
        } else {
            $invoiceQuery->whereBetween('created_at', [$startAt, $endAt]);
        }

        $invoiceQuery
            ->where(function ($query) use ($excludedStatuses) {
                $query->whereNull('status')->orWhereNotIn('status', $excludedStatuses);
            });

        if (Schema::hasColumn($invoiceTable, 'is_complimentary')) {
            $invoiceQuery->where(function ($query) {
                $query->whereNull('is_complimentary')->orWhere('is_complimentary', false);
            });
        }

        $invoiceIds = $invoiceQuery->pluck('id');
        if ($invoiceIds->isEmpty()) return $totals;

        $itemTable = (new \App\Models\InvoiceItem())->getTable();
        $serviceConditions = [];
        if (Schema::hasColumn($itemTable, 'item_type')) {
            $serviceConditions[] = "{$itemTable}.item_type = 'service'";
        }
        if (Schema::hasColumn($itemTable, 'automotive_service_id')) {
            $serviceConditions[] = "{$itemTable}.automotive_service_id IS NOT NULL";
        }
        $isServiceSql = $serviceConditions ? '(' . implode(' OR ', $serviceConditions) . ')' : '1 = 0';
        $lineTotalSql = "COALESCE({$itemTable}.total, COALESCE({$itemTable}.price, 0) * COALESCE({$itemTable}.quantity, 0))";

        $aggregates = \App\Models\InvoiceItem::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$isServiceSql} THEN {$lineTotalSql} ELSE 0 END), 0) AS services_sales")
            ->selectRaw("COALESCE(SUM(CASE WHEN NOT {$isServiceSql} THEN {$lineTotalSql} ELSE 0 END), 0) AS accessories_sales")
            ->first();

        $totals['accessories_sales'] = (float) ($aggregates?->accessories_sales ?? 0);
        $totals['services_sales'] = (float) ($aggregates?->services_sales ?? 0);

        return array_map(fn ($amount) => round($amount, 2), $totals);
    }

    /**
     * ✅ فتح وردية جديدة
     */
    public function openShift(Request $request)
    {
        $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'notes'           => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            // ✅ منع فتح وردية جديدة لو فيه وردية مفتوحة أصلاً
            $existing = CashierShift::where('status', 'open')
                ->where(function ($q) use ($user) {
                    if ($user instanceof Admin) {
                        $q->where('admin_id', $user->id);
                    } elseif ($user instanceof Employee) {
                        $q->where('employee_id', $user->id);
                    }
                })
                ->first();

            if ($existing) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'يوجد وردية مفتوحة بالفعل',
                    'data'    => new CashierShiftResource($existing),
                ], 422);
            }

            $shiftData = [
                'opening_balance' => $request->opening_balance,
                'notes'           => $request->notes,
                'opened_at'       => now(),
                'status'          => 'open',
                'cash_sales'      => 0,
                'card_sales'      => 0,
                'wallet_sales'    => 0,
                'returns_amount'  => 0,
                'expected_amount' => $request->opening_balance,
                'actual_amount'   => null,
                'difference'      => null,
            ];

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
                'data'    => new CashierShiftResource($shift->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء فتح الورديه',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ✅ إغلاق وردية
     */
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
                ->lockForUpdate()
                ->latest('opened_at')
                ->first();

            if (!$shift) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'لا توجد وردية مفتوحة حالياً',
                ], 404);
            }

            $expected = $this->calculateExpectedAmount($shift);
            $actual   = (float) $request->actual_amount;

            $shift->update([
                'closing_balance' => $actual,
                'actual_amount'   => $actual,
                'expected_amount' => $expected,
                'difference'      => round($actual - $expected, 2),
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
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ✅ جلب الوردية المفتوحة حالياً
     */
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

        // ✅ المتوقع يُحسب live من القيم الحالية
        $expected = $this->calculateExpectedAmount($shift);
        $itemSales = $this->calculateShiftItemSalesByType($shift);

        return response()->json([
            'status'  => true,
            'message' => 'آخر وردية مفتوحة',
            'data'    => array_merge($shift->toArray(), [
                'accessories_sales' => $itemSales['accessories_sales'],
                'services_sales' => $itemSales['services_sales'],
                'expected_amount' => $expected,
                'expenses_amount' => (float) Finance::query()->withoutGlobalScope('branch')->where('cashier_shift_id', $shift->id)->where('is_tracking_only', false)->sum('amount'),
            ]),
        ]);
    }

    /**
     * ✅ عرض كل الورديات
     */
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

    /**
     * ✅ عرض وردية واحدة
     */
    public function show(CashierShift $shift)
    {
        $shift->load(['employee', 'admin']);

        return response()->json([
            'status' => true,
            'data'   => new CashierShiftResource($shift),
        ]);
    }

    /**
     * ✅ تقرير الوردية الكامل
     */
    public function report(Request $request, $shiftId)
    {
        try {
            $shift = CashierShift::with(['employee', 'admin'])->findOrFail($shiftId);

            $includeInvoices = $request->boolean('include_invoices', true);
            $includeReturns  = $request->boolean('include_returns', true);
            $includeProducts = $request->boolean('include_products', true);

            $startAt = $shift->opened_at ? \Carbon\Carbon::parse($shift->opened_at) : now();
            $endAt   = $shift->closed_at ? \Carbon\Carbon::parse($shift->closed_at) : now();

            $money   = fn ($v) => round((float) ($v ?? 0), 2);
            $qtyFmt  = fn ($v) => round((float) ($v ?? 0), 3);
            $percent = fn ($part, $base) => (float) $base > 0
                ? round(((float) $part / (float) $base) * 100, 2)
                : 0;

            $classify = function ($method, string $emptyAs = 'other') {
                $m = mb_strtolower(trim((string) $method));
                if ($m === '') return $emptyAs;
                if (in_array($m, ['cash', 'نقدي', 'نقدا', 'نقداً', 'كاش'], true)) return 'cash';
                if (in_array($m, ['card', 'visa', 'بطاقة', 'بطاقه', 'فيزا'], true)) return 'card';
                if (in_array($m, ['wallet', 'محفظة', 'محفظه', 'محفظة إلكترونية', 'رصيد'], true)) return 'wallet';
                return 'other';
            };

            $unitCostOf = function ($item) {
                $snapshot = (float) ($item->unit_cost ?? $item->cost_price ?? 0);
                if ($snapshot > 0) return $snapshot;

                if (!empty($item->automotive_service_id)) {
                    $svc = $item->relationLoaded('automotiveService')
                        ? $item->automotiveService
                        : ($item->automotiveService ?? null);
                    if ($svc) return (float) ($svc->estimated_cost ?? 0);
                }

                if (!empty($item->product_id) && $item->product) {
                    return (float) ($item->product->cost ?? 0);
                }
                return 0.0;
            };

            $costQuantityOf = function ($item) {
                $qty = (float) ($item->quantity ?? 0);
                $isService = ($item->item_type ?? null) === 'service' || !empty($item->automotive_service_id);
                if ($isService) {
                    $meters = (float) ($item->meter_quantity ?? 1);
                    if ($meters > 0) return $qty * $meters;
                }
                return $qty;
            };

            $lineTotalOf = function ($item, float $quantity) use ($money) {
                return $item->total !== null
                    ? $money($item->total)
                    : $money((float) ($item->price ?? 0) * $quantity);
            };

            $productNameOf = function ($item) {
                if (!empty($item->product_name)) return $item->product_name;
                if (!empty($item->product_id) && $item->product) return $item->product->name;
                if (!empty($item->automotive_service_id)) {
                    $svc = $item->relationLoaded('automotiveService')
                        ? $item->automotiveService
                        : ($item->automotiveService ?? null);
                    if ($svc) return $svc->name;
                }
                return 'غير محدد';
            };

            $productKeyOf = function ($item, string $name) {
                if (!empty($item->automotive_service_id)) return 's' . $item->automotive_service_id;
                if (!empty($item->product_id)) return 'p' . $item->product_id;
                return 'n' . md5($name);
            };

            $isComplimentaryInvoice = function ($invoice) {
                if (!$invoice) return false;
                $val = $invoice->is_complimentary ?? false;
                if (is_bool($val)) return $val;
                if (is_numeric($val)) return ((int) $val) === 1;
                if (is_string($val)) {
                    return in_array(strtolower(trim($val)), ['1', 'true', 'yes', 'on'], true);
                }
                return false;
            };

            $repRelation = null;
            foreach (['salesRepresentative', 'salesRep', 'representative', 'salesman', 'salesperson'] as $rel) {
                if (method_exists(Invoice::class, $rel)) {
                    $repRelation = $rel;
                    break;
                }
            }
            $repColumns = ['sales_representative_id', 'sales_rep_id', 'representative_id', 'salesman_id'];

            $repOf = function ($invoice) use ($repRelation, $repColumns) {
                if (!$invoice) return ['id' => null, 'name' => null];
                $id = null;
                foreach ($repColumns as $col) {
                    if (!empty($invoice->{$col})) { $id = $invoice->{$col}; break; }
                }
                $relObj = $repRelation ? $invoice->{$repRelation} : null;
                return ['id' => $relObj?->id ?? $id, 'name' => $relObj?->name];
            };

            $injectAutomotiveServices = function ($items) {
                if (!$items || !count($items)) return;
                $serviceIds = collect($items)->pluck('automotive_service_id')->filter()->unique()->values();
                if ($serviceIds->isEmpty()) return;
                $servicesMap = \App\Models\AutomotiveService::query()
                    ->whereIn('id', $serviceIds)->get()->keyBy('id');
                foreach ($items as $item) {
                    if (!empty($item->automotive_service_id) && isset($servicesMap[$item->automotive_service_id])) {
                        $item->setRelation('automotiveService', $servicesMap[$item->automotive_service_id]);
                    }
                }
            };

            $customersReport = [];
            $repsReport      = [];

            $ensureGroup = function (array &$bag, string $key, $id, $name) {
                if (!isset($bag[$key])) {
                    $bag[$key] = [
                        'id' => $id, 'name' => $name,
                        'invoices_count' => 0, 'sales' => 0.0, 'cost' => 0.0, 'profit' => 0.0,
                        'returns_count' => 0, 'returns_amount' => 0.0, 'returns_cost' => 0.0, 'refunded' => 0.0,
                    ];
                }
            };

            $newProductRow = fn ($productId, string $name, $isService = false) => [
                'product_id'        => $productId,
                'product_name'      => $name,
                'item_type'         => $isService ? 'service' : 'product',
                'quantity'          => 0.0,
                'meters'            => 0.0,
                'selling_total'     => 0.0,
                'total_cost'        => 0.0,
                'profit'            => 0.0,
                'returned_quantity' => 0.0,
                'returned_total'    => 0.0,
                'returned_cost'     => 0.0,
            ];

            $excludedInvoiceStatuses = ['cancelled', 'canceled', 'void', 'voided', 'draft', 'ملغي', 'ملغاة', 'ملغية'];

            // ═══════════════════════════════════════════════════════════════
            // ✅ [الإصلاح الأهم] البحث عن الفواتير باستخدام cashier_shift_id
            // ═══════════════════════════════════════════════════════════════
            $invoiceTable = (new Invoice())->getTable();

            // ✅ نتحقق من كل الأعمدة المحتملة
            $hasCashierShiftId = Schema::hasColumn($invoiceTable, 'cashier_shift_id');
            $hasShiftId        = Schema::hasColumn($invoiceTable, 'shift_id');

            Log::info('Shift Report - Checking columns', [
                'shift_id' => $shiftId,
                'has_cashier_shift_id' => $hasCashierShiftId,
                'has_shift_id' => $hasShiftId,
                'start_at' => $startAt->toDateTimeString(),
                'end_at' => $endAt->toDateTimeString(),
            ]);

            $invoiceWith = ['items.product', 'items.automotiveService', 'payments', 'customer', 'treasury'];
            if ($repRelation) $invoiceWith[] = $repRelation;

            $invoiceQuery = Invoice::with($invoiceWith);

            // ✅ الحل: نستخدم cashier_shift_id (الاسم الصحيح) مع fallback على الوقت
            if ($hasCashierShiftId || $hasShiftId) {
                $shiftColumn = $hasCashierShiftId ? 'cashier_shift_id' : 'shift_id';

                $invoiceQuery->where(function ($q) use ($shift, $shiftColumn, $startAt, $endAt) {
                    // ✅ الفواتير المرتبطة بالوردية
                    $q->where($shiftColumn, $shift->id)
                      // ✅ أو الفواتير في نفس الفترة الزمنية بدون shift
                      ->orWhere(function ($x) use ($startAt, $endAt, $shiftColumn) {
                          $x->whereNull($shiftColumn)
                            ->whereBetween('created_at', [$startAt, $endAt]);
                      });
                });
            } else {
                // ✅ fallback: البحث بالوقت فقط
                $invoiceQuery->whereBetween('created_at', [$startAt, $endAt]);
            }

            $invoices = $invoiceQuery
                ->where(function ($q) use ($excludedInvoiceStatuses) {
                    $q->whereNull('status')->orWhereNotIn('status', $excludedInvoiceStatuses);
                })
                ->orderBy('created_at', 'asc')
                ->get();

            Log::info('Shift Report - Invoices found', [
                'shift_id' => $shiftId,
                'invoices_count' => $invoices->count(),
                'invoice_ids' => $invoices->pluck('id')->toArray(),
            ]);

            $totalSales = 0.0; $extraChargeTotal = 0.0; $totalCost = 0.0; $totalQuantity = 0.0; $totalMeters = 0.0;
            $compValue = 0.0; $compCost = 0.0; $compQuantity = 0.0; $compDiscount = 0.0;
            $collections = ['cash' => 0.0, 'card' => 0.0, 'wallet' => 0.0, 'other' => 0.0];

            $sales = [];
            $complimentaryInvoices = [];
            $complimentaryProducts = [];
            $productsReport = [];
            $servicesReport = [];
            $servicesTotals = [
                'services_count' => 0, 'cars_count' => 0, 'meters_count' => 0.0,
                'sales' => 0.0, 'cost' => 0.0, 'profit' => 0.0,
            ];

            $missingCostCount = 0;
            $missingCostNames = [];

            foreach ($invoices as $invoice) {
                $isComp = $isComplimentaryInvoice($invoice);
                $invoiceSales = 0.0; $invoiceCost = 0.0; $invoiceQuantity = 0.0;
                $items = []; $payments = [];

                foreach ($invoice->items as $item) {
                    $quantity     = $qtyFmt($item->quantity);
                    $costQuantity = $costQuantityOf($item);
                    $sellingPrice = $money($item->price);
                    $sellingTotal = $lineTotalOf($item, $quantity);

                    $unitCost      = $money($unitCostOf($item));
                    $itemTotalCost = $money($unitCost * $costQuantity);
                    $itemProfit    = $money($sellingTotal - $itemTotalCost);

                    $productName = $productNameOf($item);
                    $isService   = ($item->item_type ?? null) === 'service' || !empty($item->automotive_service_id);

                    if ($unitCost <= 0) {
                        $missingCostCount++;
                        $missingCostNames[$productName] = true;
                    }

                    $invoiceSales    += $sellingTotal;
                    $invoiceCost     += $itemTotalCost;
                    $invoiceQuantity += $quantity;

                    if ($isComp) {
                        $compValue    += $sellingTotal;
                        $compCost     += $itemTotalCost;
                        $compQuantity += $quantity;

                        $key = $productKeyOf($item, $productName);
                        if (!isset($complimentaryProducts[$key])) {
                            $complimentaryProducts[$key] = [
                                'product_id'   => $item->product_id,
                                'service_id'   => $item->automotive_service_id,
                                'product_name' => $productName,
                                'item_type'    => $isService ? 'service' : 'product',
                                'quantity'     => 0.0, 'meters' => 0.0,
                                'market_value' => 0.0, 'total_cost' => 0.0,
                            ];
                        }
                        $complimentaryProducts[$key]['quantity']     += $quantity;
                        $complimentaryProducts[$key]['meters']       += $isService ? $costQuantity : 0;
                        $complimentaryProducts[$key]['market_value'] += $sellingTotal;
                        $complimentaryProducts[$key]['total_cost']   += $itemTotalCost;
                    } else {
                        $totalSales    += $sellingTotal;
                        $totalCost     += $itemTotalCost;
                        $totalQuantity += $quantity;
                        $totalMeters   += $isService ? $costQuantity : 0;

                        $key = $productKeyOf($item, $productName);

                        if ($isService && !empty($item->automotive_service_id)) {
                            if (!isset($servicesReport[$key])) {
                                $svc = $item->relationLoaded('automotiveService')
                                    ? $item->automotiveService
                                    : ($item->automotiveService ?? null);
                                $servicesReport[$key] = [
                                    'service_id'   => $item->automotive_service_id,
                                    'product_id'   => $svc?->product_id,
                                    'service_name' => $svc?->name ?? $productName,
                                    'item_type'    => $svc?->item_type ?? 'service',
                                    'has_fixed_price' => (bool) ($svc?->has_fixed_price ?? true),
                                    'cars_count'   => 0.0,
                                    'meters_count' => 0.0,
                                    'selling_total'=> 0.0,
                                    'total_cost'   => 0.0,
                                    'profit'       => 0.0,
                                    'unit_cost_per_meter' => (float) ($svc?->estimated_cost ?? 0),
                                    'vehicle_sizes' => [],
                                ];
                            }
                            $servicesReport[$key]['cars_count']    += $quantity;
                            $servicesReport[$key]['meters_count']  += $costQuantity;
                            $servicesReport[$key]['selling_total'] += $sellingTotal;
                            $servicesReport[$key]['total_cost']    += $itemTotalCost;
                            $servicesReport[$key]['profit']        += $itemProfit;

                            $sizes = $item->vehicle_sizes;
                            if (is_string($sizes)) {
                                $decoded = json_decode($sizes, true);
                                $sizes = is_array($decoded) ? $decoded : [];
                            }
                            if (is_array($sizes)) {
                                foreach ($sizes as $sz) {
                                    $szName = trim((string) ($sz['name'] ?? ''));
                                    if ($szName === '') continue;
                                    if (!isset($servicesReport[$key]['vehicle_sizes'][$szName])) {
                                        $servicesReport[$key]['vehicle_sizes'][$szName] = [
                                            'name' => $szName, 'count' => 0, 'meters' => 0.0, 'revenue' => 0.0,
                                        ];
                                    }
                                    $servicesReport[$key]['vehicle_sizes'][$szName]['count']   += 1;
                                    $servicesReport[$key]['vehicle_sizes'][$szName]['meters']  += (float) ($sz['meters'] ?? 0);
                                    $servicesReport[$key]['vehicle_sizes'][$szName]['revenue'] += (float) ($sz['price'] ?? 0);
                                }
                            }
                        }

                        if (!isset($productsReport[$key])) {
                            $productsReport[$key] = $newProductRow($item->product_id, $productName, $isService);
                        }
                        $productsReport[$key]['quantity']      += $quantity;
                        $productsReport[$key]['meters']        += $isService ? $costQuantity : 0;
                        $productsReport[$key]['selling_total'] += $sellingTotal;
                        $productsReport[$key]['total_cost']    += $itemTotalCost;
                        $productsReport[$key]['profit']        += $itemProfit;
                    }

                    $items[] = [
                        'id'             => $item->id,
                        'product_id'     => $item->product_id,
                        'service_id'     => $item->automotive_service_id,
                        'item_type'      => $isService ? 'service' : 'product',
                        'product_name'   => $productName,
                        'color'          => $item->color ?? null,
                        'size'           => $item->size ?? null,
                        'vehicle_sizes'  => $item->vehicle_sizes ?? null,
                        'quantity'       => $quantity,
                        'meter_quantity' => $item->meter_quantity !== null ? (float) $item->meter_quantity : null,
                        'meters_total'   => $isService ? $qtyFmt($costQuantity) : null,
                        'selling_price'  => $sellingPrice,
                        'selling_total'  => $sellingTotal,
                        'unit_cost'      => $unitCost,
                        'total_cost'     => $itemTotalCost,
                        'profit'         => $itemProfit,
                        'cost_missing'   => $unitCost <= 0,
                    ];
                }

                foreach ($invoice->payments as $payment) {
                    $amount = $money($payment->amount);
                    $bucket = $classify($payment->method);
                    $collections[$bucket] += $amount;
                    $payments[] = ['method' => $payment->method, 'amount' => $amount];
                }

                $invoiceSales = $money($invoiceSales);
                $invoiceCost  = $money($invoiceCost);
                $invoiceExtraCharge = $isComp ? 0.0 : $money($invoice->extra_charge ?? 0);
                $rep = $repOf($invoice);

                $row = [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'date' => optional($invoice->created_at)->format('Y-m-d H:i:s'),
                    'time' => optional($invoice->created_at)->format('H:i:s'),
                    'customer' => $invoice->customer?->name,
                    'customer_id' => $invoice->customer?->id ?? ($invoice->customer_id ?? null),
                    'customer_phone' => $invoice->customer?->phone,
                    'sales_rep' => $rep['name'],
                    'sales_rep_id' => $rep['id'],
                    'treasury_id' => $invoice->treasury?->id ?? ($invoice->treasury_id ?? null),
                    'treasury_name' => $invoice->treasury?->name,
                    'items_count' => count($items),
                    'total_quantity' => $qtyFmt($invoiceQuantity),
                    'items' => $items,
                    'total_sales' => $invoiceSales,
                    'extra_charge' => $invoiceExtraCharge,
                    'sales_including_extra_charge' => $money($invoiceSales + $invoiceExtraCharge),
                    'total_cost' => $invoiceCost,
                    'profit' => $money($invoiceSales - $invoiceCost),
                    'payments' => $payments,
                    'payment_total' => $money(array_sum(array_column($payments, 'amount'))),
                    'status' => $invoice->status,
                    'is_complimentary' => $isComp,
                    'discount_percentage' => (float) ($invoice->discount_percentage ?? 0),
                    'discount_amount' => $money($invoice->discount_amount),
                ];

                if ($isComp) {
                    $compDiscount += $money($invoice->discount_amount);
                    $row['market_value'] = $invoiceSales;
                    $row['cost_borne']   = $invoiceCost;
                    $complimentaryInvoices[] = $row;
                    continue;
                }

                $extraChargeTotal += $invoiceExtraCharge;
                $cid = $invoice->customer?->id ?? ($invoice->customer_id ?? null);
                $ck  = $cid ? 'c' . $cid : 'walkin';
                $ensureGroup($customersReport, $ck, $cid, $invoice->customer?->name);
                $customersReport[$ck]['invoices_count']++;
                $customersReport[$ck]['sales'] += $invoiceSales;
                $customersReport[$ck]['cost']  += $invoiceCost;

                $rk = $rep['id'] ? 'r' . $rep['id'] : ($rep['name'] ? 'n' . md5($rep['name']) : 'none');
                $ensureGroup($repsReport, $rk, $rep['id'], $rep['name']);
                $repsReport[$rk]['invoices_count']++;
                $repsReport[$rk]['sales'] += $invoiceSales;
                $repsReport[$rk]['cost']  += $invoiceCost;

                $sales[] = $row;
            }

            $totalSales    = $money($totalSales);
            $extraChargeTotal = $money($extraChargeTotal);
            $totalCost     = $money($totalCost);
            $totalQuantity = $qtyFmt($totalQuantity);
            $totalMeters   = $qtyFmt($totalMeters);

            $compValue = $money($compValue);
            $compCost  = $money($compCost);
            $compQuantity = $qtyFmt($compQuantity);
            $compDiscount = $money($compDiscount);

            foreach ($collections as $k => $v) $collections[$k] = $money($v);

            $complimentaryProducts = array_map(function ($p) use ($money, $qtyFmt) {
                $p['quantity']     = $qtyFmt($p['quantity']);
                $p['meters']       = $qtyFmt($p['meters'] ?? 0);
                $p['market_value'] = $money($p['market_value']);
                $p['total_cost']   = $money($p['total_cost']);
                return $p;
            }, array_values($complimentaryProducts));
            usort($complimentaryProducts, fn ($a, $b) => $b['total_cost'] <=> $a['total_cost']);

            $shiftInvoiceIds = $invoices->pluck('id')->toArray();
            $salesReturnTable = (new SalesInvoiceReturn())->getTable();
            $hasSalesReturnShiftColumn = \Illuminate\Support\Facades\Schema::hasColumn($salesReturnTable, 'shift_id');

            $posReturnRelations = ['invoice.customer', 'invoice.treasury', 'refundTreasury', 'items.product'];
            if (method_exists(\App\Models\ReturnItem::class, 'automotiveService')) {
                $posReturnRelations[] = 'items.automotiveService';
            }

            $posReturns = ReturnInvoice::with($posReturnRelations)
                ->where('shift_id', $shift->id)
                ->whereBetween('created_at', [$startAt, $endAt])
                ->where(function ($q) {
                    $q->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'cancelled');
                })
                ->orderBy('created_at', 'asc')
                ->get();

            if (!method_exists(\App\Models\ReturnItem::class, 'automotiveService')) {
                foreach ($posReturns as $ret) {
                    $injectAutomotiveServices($ret->items);
                }
            }

            $salesReturnRelations = ['invoice.customer', 'items.product', 'treasury'];
            $salesReturnItemClass = \App\Models\SalesInvoiceReturnItem::class;
            if (class_exists($salesReturnItemClass) && method_exists($salesReturnItemClass, 'automotiveService')) {
                $salesReturnRelations[] = 'items.automotiveService';
            }

            $salesReturns = SalesInvoiceReturn::with($salesReturnRelations)
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
                    $q->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'cancelled');
                })
                ->orderBy('created_at', 'asc')
                ->get()
                ->unique('id');

            if (class_exists($salesReturnItemClass) && !method_exists($salesReturnItemClass, 'automotiveService')) {
                foreach ($salesReturns as $ret) {
                    $injectAutomotiveServices($ret->items);
                }
            }

            if ($repRelation) $posReturns->loadMissing('invoice.' . $repRelation);

            $allReturns = $posReturns->concat($salesReturns)->values();
            $returns = $allReturns->reject(fn ($r) => $isComplimentaryInvoice($r->invoice))->values();
            $complimentaryReturnsSkipped = $allReturns->count() - $returns->count();

            $returnsCount = $returns->count();
            $returnsAmount = 0.0; $returnsCost = 0.0; $returnsQuantity = 0.0;
            $refunds = ['cash' => 0.0, 'card' => 0.0, 'wallet' => 0.0, 'other' => 0.0];
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

                $returnItemsTotal = 0.0; $returnCost = 0.0; $returnQuantity = 0.0; $returnItems = [];

                foreach ($return->items as $item) {
                    $quantity     = $qtyFmt($item->quantity);
                    $costQuantity = $costQuantityOf($item);
                    $price        = $money($item->price);
                    $lineTotal    = $lineTotalOf($item, $quantity);

                    $unitCost = $money($unitCostOf($item));
                    $lineCost = $money($unitCost * $costQuantity);
                    $productName = $productNameOf($item);
                    $isService = ($item->item_type ?? null) === 'service' || !empty($item->automotive_service_id);

                    if ($unitCost <= 0) {
                        $missingCostCount++;
                        $missingCostNames[$productName] = true;
                    }

                    $returnItemsTotal += $lineTotal;
                    $returnCost       += $lineCost;
                    $returnQuantity   += $quantity;

                    $key = $productKeyOf($item, $productName);
                    if (!isset($productsReport[$key])) {
                        $productsReport[$key] = $newProductRow($item->product_id, $productName, $isService);
                    }
                    $productsReport[$key]['returned_quantity'] += $quantity;
                    $productsReport[$key]['returned_total']    += $lineTotal;
                    $productsReport[$key]['returned_cost']     += $lineCost;

                    if ($isService && !empty($item->automotive_service_id) && isset($servicesReport[$key])) {
                        $servicesReport[$key]['cars_count']    -= $quantity;
                        $servicesReport[$key]['meters_count']  -= $costQuantity;
                        $servicesReport[$key]['selling_total'] -= $lineTotal;
                        $servicesReport[$key]['total_cost']    -= $lineCost;
                        $servicesReport[$key]['profit']        -= ($lineTotal - $lineCost);
                    }

                    $returnItems[] = [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'service_id' => $item->automotive_service_id,
                        'item_type' => $isService ? 'service' : 'product',
                        'product_name' => $productName,
                        'quantity' => $quantity,
                        'meter_quantity' => $item->meter_quantity !== null ? (float) $item->meter_quantity : null,
                        'meters_total' => $isService ? $qtyFmt($costQuantity) : null,
                        'selling_price' => $price,
                        'selling_total' => $lineTotal,
                        'unit_cost' => $unitCost,
                        'total_cost' => $lineCost,
                        'profit_reversed' => $money($lineTotal - $lineCost),
                        'reason' => $note,
                        'color' => $item->color ?? null,
                        'size' => $item->size ?? null,
                        'cost_missing' => $unitCost <= 0,
                    ];
                }

                $returnItemsTotal = $money($returnItemsTotal);
                $returnCost       = $money($returnCost);
                $returnAmount = $returnItemsTotal > 0 ? $returnItemsTotal : $documentAmount;

                $returnsAmount   += $returnAmount;
                $returnsCost     += $returnCost;
                $returnsQuantity += $returnQuantity;
                $refunds[$classify($returnMethod, 'cash')] += $refundedAmount;

                $rcid = $invoiceRef?->customer?->id ?? ($return->customer_id ?? null);
                $rck  = $rcid ? 'c' . $rcid : 'walkin';
                $ensureGroup($customersReport, $rck, $rcid,
                    $invoiceRef?->customer?->name ?? ($isPos ? null : $return->customer?->name));
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
                    'id' => $return->id,
                    'return_number' => $return->return_number,
                    'date' => optional($return->created_at)->format('Y-m-d H:i:s'),
                    'source' => $isPos ? 'pos' : 'sales',
                    'sales_invoice_id'=> $isPos ? $return->invoice_id : $return->sales_invoice_id,
                    'invoice_number' => $invoiceRef?->invoice_number,
                    'invoice_id' => $invoiceRef?->id,
                    'customer' => $invoiceRef?->customer?->name ?? ($isPos ? null : $return->customer?->name),
                    'customer_id' => $invoiceRef?->customer?->id ?? ($return->customer_id ?? null),
                    'sales_rep' => $rrep['name'],
                    'return_method' => $returnMethod,
                    'is_direct' => $isPos ? false : (bool) ($return->is_direct ?? false),
                    'status' => $status,
                    'note' => $note,
                    'treasury_id' => $isPos ? ($return->refund_treasury_id ?? $invoiceRef?->treasury_id) : $return->treasury_id,
                    'treasury_name' => $isPos ? ($return->refundTreasury?->name ?? $invoiceRef?->treasury?->name) : $return->treasury?->name,
                    'items_count' => count($returnItems),
                    'total_quantity' => $qtyFmt($returnQuantity),
                    'items' => $returnItems,
                    'document_amount' => $documentAmount,
                    'total_amount' => $returnAmount,
                    'refunded_amount' => $refundedAmount,
                    'total_cost' => $returnCost,
                    'profit_reversed' => $money($returnAmount - $returnCost),
                ];
            }

            $returnsAmount   = $money($returnsAmount);
            $returnsCost     = $money($returnsCost);
            $returnsQuantity = $qtyFmt($returnsQuantity);
            foreach ($refunds as $k => $v) $refunds[$k] = $money($v);

            $productsReport = array_map(function ($p) use ($money, $qtyFmt, $percent) {
                $p['quantity']          = $qtyFmt($p['quantity']);
                $p['meters']            = $qtyFmt($p['meters'] ?? 0);
                $p['selling_total']     = $money($p['selling_total']);
                $p['total_cost']        = $money($p['total_cost']);
                $p['profit']            = $money($p['selling_total'] - $p['total_cost']);
                $p['returned_quantity'] = $qtyFmt($p['returned_quantity']);
                $p['returned_total']    = $money($p['returned_total']);
                $p['returned_cost']     = $money($p['returned_cost']);
                $p['profit_margin']     = $percent($p['profit'], $p['selling_total']);
                $p['net_quantity']      = $qtyFmt($p['quantity'] - $p['returned_quantity']);
                $p['net_sales']         = $money($p['selling_total'] - $p['returned_total']);
                $p['net_cost']          = $money($p['total_cost'] - $p['returned_cost']);
                $p['net_profit']        = $money($p['net_sales'] - $p['net_cost']);
                return $p;
            }, array_values($productsReport));
            usort($productsReport, fn ($a, $b) => $b['profit'] <=> $a['profit']);

            // Split sold line-item values for the shift receipt. These are gross
            // sales before returns; returns remain shown separately in the report.
            $salesByItemType = ['accessories' => 0.0, 'services' => 0.0];
            foreach ($productsReport as $productRow) {
                $bucket = ($productRow['item_type'] ?? 'product') === 'service'
                    ? 'services'
                    : 'accessories';
                $salesByItemType[$bucket] += (float) ($productRow['selling_total'] ?? 0);
            }
            $salesByItemType = array_map($money, $salesByItemType);

            $servicesReport = array_map(function ($s) use ($money, $qtyFmt, $percent) {
                $s['cars_count']    = $qtyFmt($s['cars_count']);
                $s['meters_count']  = $qtyFmt($s['meters_count']);
                $s['selling_total'] = $money($s['selling_total']);
                $s['total_cost']    = $money($s['total_cost']);
                $s['profit']        = $money($s['selling_total'] - $s['total_cost']);
                $s['profit_margin'] = $percent($s['profit'], $s['selling_total']);
                $s['avg_price_per_car'] = $s['cars_count'] > 0
                    ? $money($s['selling_total'] / $s['cars_count']) : 0;
                $s['vehicle_sizes'] = array_values(array_map(function ($v) use ($money, $qtyFmt) {
                    $v['count']   = $qtyFmt($v['count']);
                    $v['meters']  = $qtyFmt($v['meters']);
                    $v['revenue'] = $money($v['revenue']);
                    return $v;
                }, $s['vehicle_sizes'] ?? []));
                return $s;
            }, array_values($servicesReport));
            usort($servicesReport, fn ($a, $b) => $b['selling_total'] <=> $a['selling_total']);

            foreach ($servicesReport as $s) {
                $servicesTotals['services_count']++;
                $servicesTotals['cars_count']    += $s['cars_count'];
                $servicesTotals['meters_count']  += $s['meters_count'];
                $servicesTotals['sales']         += $s['selling_total'];
                $servicesTotals['cost']          += $s['total_cost'];
                $servicesTotals['profit']        += $s['profit'];
            }
            $servicesTotals['cars_count']   = $qtyFmt($servicesTotals['cars_count']);
            $servicesTotals['meters_count'] = $qtyFmt($servicesTotals['meters_count']);
            $servicesTotals['sales']        = $money($servicesTotals['sales']);
            $servicesTotals['cost']         = $money($servicesTotals['cost']);
            $servicesTotals['profit']       = $money($servicesTotals['profit']);
            $servicesTotals['profit_margin']= $percent($servicesTotals['profit'], $servicesTotals['sales']);

            $finalizeGroups = function (array $rows) use ($money) {
                $rows = array_map(function ($r) use ($money) {
                    foreach (['sales','cost','returns_amount','returns_cost','refunded'] as $f) {
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
            $netProfit     = $money($netSales - $netCost);
            $profitAfterComplimentary = $money($netProfit - $compCost);

            $totalRefunded    = $money(array_sum($refunds));
            $collectionsTotal = $money(array_sum($collections));

            $shiftExpenses = Finance::query()
                ->withoutGlobalScope('branch')
                ->where('cashier_shift_id', $shift->id)
                ->where('is_tracking_only', false)
                ->orderBy('date')
                ->orderBy('id')
                ->get(['id', 'amount', 'description', 'date', 'payment_method', 'reference_number'])
                ->map(fn (Finance $expense) => [
                    'id' => $expense->id,
                    'amount' => $money($expense->amount),
                    'description' => $expense->description,
                    'date' => $expense->date?->toDateString(),
                    'payment_method' => $expense->payment_method,
                    'reference_number' => $expense->reference_number,
                ])
                ->values()
                ->all();
            $shiftExpensesTotal = $money(array_sum(array_column($shiftExpenses, 'amount')));

            $openingBalance = $money($shift->opening_balance);
            $cashSales      = $money($collections['cash']);
            $cashReturns    = $money($refunds['cash']);

            $expectedCash   = $money($openingBalance + $cashSales - $cashReturns - $shiftExpensesTotal);

            $savedExpected = $shift->expected_amount !== null ? $money($shift->expected_amount) : null;
            $finalExpected = $savedExpected ?? $expectedCash;

            $actualAmount = $shift->actual_amount !== null ? $money($shift->actual_amount) : null;
            $difference   = $actualAmount !== null
                ? $money($actualAmount - $finalExpected)
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
            $branchId   = $shift->employee?->branch_id ?? ($shift->branch_id ?? null);
            $branchName = null;

            if ($shift->employee && method_exists($shift->employee, 'branch')) {
                $branchName = $shift->employee->branch?->name;
            }
            if (!$branchName && $branchId && \Illuminate\Support\Facades\Schema::hasTable('branches')) {
                foreach (['name', 'name_ar', 'branch_name', 'title'] as $col) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('branches', $col)) {
                        $branchName = DB::table('branches')->where('id', $branchId)->value($col);
                        break;
                    }
                }
            }

            $durationMinutes = $shift->opened_at
                ? (int) round(abs($endAt->getTimestamp() - $startAt->getTimestamp()) / 60)
                : 0;

            $compItemsCount = array_sum(array_map(fn($inv) => count($inv['items'] ?? []), $complimentaryInvoices));
            $compCustomersCount = count(array_unique(array_filter(array_column($complimentaryInvoices, 'customer_id'))));
            $compAvgMarketValue = count($complimentaryInvoices) > 0 ? $money($compValue / count($complimentaryInvoices)) : 0;
            $compAvgCost        = count($complimentaryInvoices) > 0 ? $money($compCost / count($complimentaryInvoices)) : 0;
            $compWithoutCostCount = count(array_filter($complimentaryInvoices, fn($inv) => ($inv['cost_borne'] ?? 0) <= 0));

            $data = [
                'shift' => [
                    'id' => $shift->id,
                    'cashier' => $cashierName,
                    'cashier_employee' => [
                        'id' => $shift->employee?->id,
                        'name' => $shift->employee?->name,
                        'phone' => $shift->employee?->phone,
                    ],
                    'opened_by_admin' => [
                        'id' => $shift->admin?->id,
                        'name' => $shift->admin?->name,
                    ],
                    'branch_id' => $branchId,
                    'branch_name' => $branchName,
                    'opened_at' => $shift->opened_at,
                    'closed_at' => $shift->closed_at,
                    'duration_minutes' => $durationMinutes,
                    'status' => $shift->status,
                    'accessories_sales' => $salesByItemType['accessories'],
                    'services_sales' => $salesByItemType['services'],
                ],

                'summary' => [
                    'sales' => [
                        'invoices_count'     => count($sales),
                        'all_invoices_count' => count($sales) + count($complimentaryInvoices),
                        'total_quantity'     => $totalQuantity,
                        'total_meters'       => $totalMeters,
                        'gross_sales'        => $totalSales,
                        'accessories_sales'  => $salesByItemType['accessories'],
                        'services_sales'     => $salesByItemType['services'],
                        'by_type'            => $salesByItemType,
                        'extra_charge_total' => $extraChargeTotal,
                        'sales_including_extra_charge' => $money($totalSales + $extraChargeTotal),
                        'total_cost'         => $totalCost,
                        'gross_profit'       => $grossProfit,
                        'profit_margin'      => $percent($grossProfit, $totalSales),
                    ],

                    'services' => $servicesTotals,

                    'returns' => [
                        'count' => $returnsCount,
                        'total_quantity' => $returnsQuantity,
                        'total_amount' => $returnsAmount,
                        'total_refunded' => $totalRefunded,
                        'total_cost' => $returnsCost,
                        'profit_reversed' => $returnsProfit,
                        'profit_margin' => $percent($returnsProfit, $returnsAmount),
                    ],

                    'expenses' => [
                        'count' => count($shiftExpenses),
                        'total_amount' => $shiftExpensesTotal,
                    ],

                    'net' => [
                        'sales' => $netSales,
                        'cost' => $netCost,
                        'profit' => $netProfit,
                        'profit_margin' => $percent($netProfit, $netSales),
                    ],

                    'complimentary' => [
                        'count' => count($complimentaryInvoices),
                        'total_quantity' => $compQuantity,
                        'market_value' => $compValue,
                        'discount_amount' => $compDiscount,
                        'total_cost' => $compCost,
                        'profit_after_complimentary' => $profitAfterComplimentary,
                        'items_count' => $compItemsCount,
                        'customers_count' => $compCustomersCount,
                        'average_market_value' => $compAvgMarketValue,
                        'average_cost' => $compAvgCost,
                        'without_cost_count' => $compWithoutCostCount,
                    ],
                ],

                'collections' => [
                    'cash' => $collections['cash'],
                    'card' => $collections['card'],
                    'wallet' => $collections['wallet'],
                    'other' => $collections['other'],
                    'total' => $collectionsTotal,
                ],

                'returns_breakdown' => [
                    'cash' => $refunds['cash'],
                    'card' => $refunds['card'],
                    'wallet' => $refunds['wallet'],
                    'other' => $refunds['other'],
                    'total' => $totalRefunded,
                    'by_source' => $returnsBySource,
                ],

                'reconciliation' => [
                    'opening_balance' => $openingBalance,
                    'cash_sales'      => $cashSales,
                    'cash_returns'    => $cashReturns,
                    'expenses'        => $shiftExpensesTotal,
                    'expected_amount' => $finalExpected,
                    'actual_amount'   => $actualAmount,
                    'difference'      => $difference,
                    'status'          => $reconciliationStatus,
                ],

                'checks' => [
                    'items_without_cost_count' => $missingCostCount,
                    'items_without_cost_names' => array_slice(array_keys($missingCostNames), 0, 10),
                    'collections_minus_sales'  => $money($collectionsTotal - $totalSales),
                    'complimentary_returns_skipped' => $complimentaryReturnsSkipped,
                    'complimentary_without_cost'    => $compWithoutCostCount,
                ],

                'customers'             => $customersReport,
                'sales_representatives' => $repsReport,
                'expenses' => $shiftExpenses,

                'top_products' => array_slice($productsReport, 0, 10),
                'top_services' => array_slice($servicesReport, 0, 10),
            ];

            if ($includeInvoices) $data['sales_invoices'] = $sales;
            if ($includeReturns)   $data['returns_details'] = $returnInvoices;

            $data['complimentary_invoices'] = $complimentaryInvoices;
            $data['complimentary_products'] = $complimentaryProducts;

            if ($includeProducts) {
                $data['products'] = [
                    'items'  => $productsReport,
                    'totals' => [
                        'products_count'    => count($productsReport),
                        'total_quantity'    => $totalQuantity,
                        'total_meters'      => $totalMeters,
                        'total_sales'       => $totalSales,
                        'total_cost'        => $totalCost,
                        'total_profit'      => $grossProfit,
                        'returned_quantity' => $returnsQuantity,
                        'returned_total'    => $returnsAmount,
                        'returned_cost'     => $returnsCost,
                        'net_profit'        => $netProfit,
                    ],
                ];

                $data['services'] = [
                    'items'  => $servicesReport,
                    'totals' => $servicesTotals,
                ];
            }

            return response()->json([
                'status' => true,
                'data'   => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('Cashier shift report failed', [
                'shift_id' => $shiftId,
                'error'    => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء إنشاء تقرير الوردية',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
