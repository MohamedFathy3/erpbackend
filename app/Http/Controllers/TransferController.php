<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\TransferRequest;
use App\Http\Resources\TransferResource;
use App\Models\Bank;
use App\Models\Transfer;
use App\Models\Treasury;
use App\Models\User;
use App\Services\AccountLedgerService;
use App\Services\TransferPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    public function transfer(TransferRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $amount = $request->amount;
                $type   = $request->type;
                $ledger = app(AccountLedgerService::class);
                foreach (['from_treasury_id', 'to_treasury_id'] as $key) {
                    if ($request->filled($key)) $ledger->initializeTreasuryAccount(Treasury::query()->findOrFail($request->input($key)));
                }
                foreach (['from_bank_id', 'to_bank_id'] as $key) {
                    if ($request->filled($key)) $ledger->initializeBankAccount(Bank::query()->findOrFail($request->input($key)));
                }

                // ================= خصم أو إضافة =================
                if (in_array($type, ['treasury_to_treasury','treasury_to_bank','treasury_withdraw'])) {
                    $source = Treasury::lockForUpdate()->findOrFail($request->from_treasury_id);
                    if ($source->balance < $amount) throw new \Exception('الرصيد غير كافي في الخزنة المصدر');
                    $source->decrement('balance', $amount);
                }

                if (in_array($type, ['bank_to_treasury','bank_to_bank','bank_withdraw'])) {
                    $source = Bank::lockForUpdate()->findOrFail($request->from_bank_id);
                    if ($source->balance < $amount) throw new \Exception('الرصيد غير كافي في البنك المصدر');
                    $source->decrement('balance', $amount);
                }

                if ($type === 'bank_to_bank') {
                    $destination = Bank::lockForUpdate()->findOrFail($request->to_bank_id);
                    $destination->increment('balance', $amount);
                }

                if (in_array($type, ['treasury_to_treasury','bank_to_treasury','treasury_deposit'])) {
                    $destination = Treasury::lockForUpdate()->findOrFail($request->to_treasury_id);
                    $destination->increment('balance', $amount);
                }

                if (in_array($type, ['treasury_to_bank','bank_deposit'])) {
                    $destination = Bank::lockForUpdate()->findOrFail($request->to_bank_id);
                    $destination->increment('balance', $amount);
                }

                // ================= تسجيل الحركة =================
                $transfer = Transfer::create([
                    'type'             => $type,
                    'from_treasury_id' => $request->from_treasury_id,
                    'to_treasury_id'   => $request->to_treasury_id,
                    'from_bank_id'     => $request->from_bank_id,
                    'to_bank_id'       => $request->to_bank_id,
                    'amount'           => $amount,
                    'currency'         => $request->currency,
                    'notes'            => $request->notes,
                    'created_by'       => auth()->user() instanceof User ? auth()->user()->id : null,
                ]);
                app(TransferPostingService::class)->post($transfer);
            });

            return JsonResponse::respondSuccess('تم تسجيل الحركة بنجاح');

        } catch (\Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

 
    public function treasuryMovements(Request $request)
    {
        try {
            $filters  = $request->input('filters', []);
            $orderDir = strtolower($request->input('orderByDirection', 'desc')) === 'asc' ? 'asc' : 'desc';
            $perPage  = max(1, (int) $request->input('perPage', 10));
            $page     = max(1, (int) $request->input('page', 1));
            $paginate = $request->boolean('paginate', true);

            // ============================================================
            // 1) التحويلات (جدول transfers) - زي ما كانت
            // ============================================================
            $treasuryTypes = [
                'treasury_to_treasury',
                'treasury_to_bank',
                'bank_to_treasury',
                'treasury_deposit',
                'treasury_withdraw',
            ];

            $transfersQuery = Transfer::with(['fromTreasury', 'toTreasury', 'fromBank', 'toBank'])
                ->whereIn('type', $treasuryTypes);

            if (!empty($filters['treasury_id'])) {
                $transfersQuery->where(function ($q) use ($filters) {
                    $q->where('from_treasury_id', $filters['treasury_id'])
                      ->orWhere('to_treasury_id', $filters['treasury_id']);
                });
            }
            if (!empty($filters['type'])) {
                $transfersQuery->where('type', $filters['type']);
            }
            if (!empty($filters['date_from'])) {
                $transfersQuery->whereDate('created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $transfersQuery->whereDate('created_at', '<=', $filters['date_to']);
            }

            // ============================================================
            // 2) حركات الخزينة (جدول treasury_transactions): مشتريات، مبيعات، سندات...
            //    بنستبعد اللي مرجعه Transfer عشان ما يتكررش مع الجدول الأول.
            // ============================================================
            $txQuery = \App\Models\TreasuryTransaction::with(['treasury', 'createdBy'])
                ->where(function ($q) {
                    $q->whereNull('reference_type')
                      ->orWhere('reference_type', '!=', Transfer::class);
                });

            if (!empty($filters['treasury_id'])) {
                $txQuery->where('treasury_id', $filters['treasury_id']);
            }

            // فلتر النوع: withdraw = حركة صادرة، deposit = حركة واردة، وباقي الأنواع خاصة بالتحويلات بس
            $includeTransactions = true;
            if (!empty($filters['type'])) {
                if ($filters['type'] === 'treasury_withdraw') {
                    $txQuery->where('type', 'out');
                } elseif ($filters['type'] === 'treasury_deposit') {
                    $txQuery->where('type', 'in');
                } else {
                    $includeTransactions = false;
                }
            }
            if (!empty($filters['date_from'])) {
                $txQuery->whereDate('created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $txQuery->whereDate('created_at', '<=', $filters['date_to']);
            }

            // ============================================================
            // 3) توحيد الشكل (نفس شكل TransferResource عشان الفرونت ما يتغيرش)
            // ============================================================
            $referenceLabels = [
                \App\Models\PurchaseInvoice::class => 'فاتورة مشتريات',
            ];

            $items = [];

            foreach ($transfersQuery->get() as $row) {
                $data = (new TransferResource($row))->resolve($request);
                $data['source'] = 'transfer';

                $items[] = [
                    'ts'   => optional($row->created_at)->getTimestamp() ?? 0,
                    'id'   => (int) $row->id,
                    'data' => $data,
                ];
            }

            if ($includeTransactions) {
                foreach ($txQuery->get() as $tx) {
                    $isIn = $tx->type === 'in';

                    // نبني Transfer مؤقت (مش بيتحفظ) عشان نستخدم نفس الـ Resource
                    $fake = new Transfer();
                    $fake->forceFill([
                        'id'               => 'tx-' . $tx->id,
                        'type'             => $isIn ? 'treasury_deposit' : 'treasury_withdraw',
                        'from_treasury_id' => $isIn ? null : $tx->treasury_id,
                        'to_treasury_id'   => $isIn ? $tx->treasury_id : null,
                        'from_bank_id'     => null,
                        'to_bank_id'       => null,
                        'amount'           => $tx->amount,
                        'currency'         => $tx->currency ?? null,
                        'notes'            => $tx->description,
                        'created_by'       => $tx->created_by,
                        'created_at'       => $tx->created_at,
                        'updated_at'       => $tx->updated_at,
                    ]);
                    $fake->setRelation('fromTreasury', $isIn ? null : $tx->treasury);
                    $fake->setRelation('toTreasury', $isIn ? $tx->treasury : null);
                    $fake->setRelation('fromBank', null);
                    $fake->setRelation('toBank', null);
                    $fake->setRelation('createdBy', $tx->createdBy);

                    $data = (new TransferResource($fake))->resolve($request);

                    $refNumber = null;
                    try {
                        $refNumber = $tx->reference?->invoice_number
                            ?? $tx->reference?->number
                            ?? null;
                    } catch (\Throwable $e) {
                        $refNumber = null;
                    }

                    $data['source']          = 'treasury_transaction';
                    $data['reference_type']  = $tx->reference_type ? class_basename($tx->reference_type) : null;
                    $data['reference_id']    = $tx->reference_id;
                    $data['reference_label'] = $referenceLabels[$tx->reference_type] ?? null;
                    $data['reference_number'] = $refNumber;

                    $items[] = [
                        'ts'   => optional($tx->created_at)->getTimestamp() ?? 0,
                        'id'   => (int) $tx->id,
                        'data' => $data,
                    ];
                }
            }

            // ============================================================
            // 4) الترتيب (الأحدث أولاً افتراضياً)
            // ============================================================
            usort($items, function ($a, $b) use ($orderDir) {
                $cmp = [$a['ts'], $a['id']] <=> [$b['ts'], $b['id']];
                return $orderDir === 'asc' ? $cmp : -$cmp;
            });

            $all = array_map(fn ($i) => $i['data'], $items);

            // ============================================================
            // 5) الـ Pagination
            // ============================================================
            if ($paginate) {
                $total = count($all);

                $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                    array_slice($all, ($page - 1) * $perPage, $perPage),
                    $total,
                    $perPage,
                    $page,
                    ['path' => $request->url(), 'query' => $request->query()]
                );

                return response()->json([
                    'data'  => $paginator->items(),
                    'links' => [
                        'first' => $paginator->url(1),
                        'last'  => $paginator->url($paginator->lastPage()),
                        'prev'  => $paginator->previousPageUrl(),
                        'next'  => $paginator->nextPageUrl(),
                    ],
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'from'         => $paginator->firstItem(),
                        'last_page'    => $paginator->lastPage(),
                        'per_page'     => $paginator->perPage(),
                        'total'        => $paginator->total(),
                    ],
                    'result'  => 'Success',
                    'message' => 'Treasury movements fetched successfully',
                    'status'  => 200,
                ]);
            }

            return response()->json([
                'data'    => $all,
                'result'  => 'Success',
                'message' => 'Treasury movements fetched successfully',
                'status'  => 200,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'result'  => 'Error',
                'message' => $e->getMessage(),
                'status'  => 500,
            ]);
        }
    }

    public function bankMovements(Request $request)
    {
        try {
            $filters   = $request->input('filters', []);
            $orderBy   = $request->input('orderBy', 'id');
            $orderDir  = $request->input('orderByDirection', 'desc');
            $perPage   = $request->input('perPage', 10);
            $paginate  = $request->boolean('paginate', true);

            // الحركات الخاصة بالبنك
            $bankTypes = [
                'treasury_to_bank',
                'bank_to_treasury',
                'bank_to_bank',
                'bank_deposit',
                'bank_withdraw',
            ];

            $query = Transfer::with([
                'fromTreasury',
                'toTreasury',
                'fromBank',
                'toBank'
            ])
            ->whereIn('type', $bankTypes);

            // ================= FILTERS =================
            if (!empty($filters['bank_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('from_bank_id', $filters['bank_id'])
                    ->orWhere('to_bank_id', $filters['bank_id']);
                });
            }

            if (!empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            // ================= SORT =================
            $query->orderBy($orderBy, $orderDir);

            // ================= PAGINATION =================
            if ($paginate) {
                $rows = $query->paginate($perPage);

                return response()->json([
                    'data' => TransferResource::collection($rows->items()),
                    'links' => [
                        'first' => $rows->url(1),
                        'last'  => $rows->url($rows->lastPage()),
                        'prev'  => $rows->previousPageUrl(),
                        'next'  => $rows->nextPageUrl(),
                    ],
                    'meta' => [
                        'current_page' => $rows->currentPage(),
                        'from'         => $rows->firstItem(),
                        'last_page'    => $rows->lastPage(),
                        'per_page'     => $rows->perPage(),
                        'total'        => $rows->total(),
                    ],
                    'result'  => 'Success',
                    'message' => 'Bank movements fetched successfully',
                    'status'  => 200,
                ]);
            }

            $rows = $query->get();

            return response()->json([
                'data' => TransferResource::collection($rows),
                'result'  => 'Success',
                'message' => 'Bank movements fetched successfully',
                'status'  => 200,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ]);
        }
    }
}
