<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\RevenueRequest;
use App\Http\Resources\RevenueResource;
use App\Interfaces\RevenueRepositoryInterface;
use App\Models\Revenue;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RevenueController extends BaseController
{
    protected mixed $crudRepository;

    public function __construct(RevenueRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    public function index()
    {
        try {
            // ✅ تأكد من تحميل العلاقات
            $revenues = $this->crudRepository->all(
                [],
                ['treasury', 'currency', 'branch'],
                ['*']
            );
            
            $Revenue = RevenueResource::collection($revenues);
            return $Revenue->additional(JsonResponse::success());
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function store(RevenueRequest $request)
    {
        DB::beginTransaction();
        try {
            $revenue = $this->crudRepository->create($request->validated());

            if ($request->has('treasury_id') && $request->treasury_id) {
                $this->createTreasuryTransaction($revenue);
            }

            DB::commit();
            
            // ✅ تحميل العلاقات قبل الـ Response
            $revenue->load(['treasury', 'currency', 'branch']);
            
            return new RevenueResource($revenue);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Revenue store error: ' . $e->getMessage());
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function show(Revenue $Revenue): ?\Illuminate\Http\JsonResponse
    {
        try {
            // ✅ تحميل العلاقات
            $Revenue->load(['treasury', 'currency', 'branch']);
            
            return JsonResponse::respondSuccess(
                'Item Fetched Successfully', 
                new RevenueResource($Revenue)
            );
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function update(RevenueRequest $request, Revenue $Revenue)
    {
        DB::beginTransaction();
        try {
            $this->crudRepository->update($request->validated(), $Revenue->id);
            
            if ($request->has('treasury_id') && $request->treasury_id) {
                $this->updateTreasuryTransaction($Revenue);
            }

            activity()->performedOn($Revenue)->withProperties(['attributes' => $Revenue])->log('update');
            DB::commit();
            
            // ✅ تحميل العلاقات بعد التحديث
            $Revenue->load(['treasury', 'currency', 'branch']);
            
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_UPDATED_SUCCESSFULLY));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Revenue update error: ' . $e->getMessage());
            return JsonResponse::respondError($e->getMessage());
        }
    }

    // ... باقي الدوال

    private function createTreasuryTransaction($revenue)
    {
        $treasury = Treasury::find($revenue->treasury_id);
        if (!$treasury) {
            Log::warning("⚠️ Treasury not found for revenue: {$revenue->id}");
            return;
        }

        $amount = $revenue->amount;
        $oldBalance = $treasury->balance;
        $treasury->increment('balance', $amount);

        TreasuryTransaction::create([
            'treasury_id' => $revenue->treasury_id,
            'reference_type' => Revenue::class,
            'reference_id' => $revenue->id,
            'type' => 'in',
            'amount' => $amount,
            'description' => "إيراد: {$revenue->category} - " . ($revenue->description ?? 'بدون وصف'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info("💰 Treasury updated from revenue", [
            'revenue_id' => $revenue->id,
            'treasury_id' => $revenue->treasury_id,
            'amount' => $amount,
            'old_balance' => $oldBalance,
            'new_balance' => $treasury->balance,
        ]);
    }

    private function updateTreasuryTransaction($revenue)
    {
        $oldTransaction = TreasuryTransaction::where('reference_type', Revenue::class)
            ->where('reference_id', $revenue->id)
            ->first();

        if ($oldTransaction) {
            $oldTreasury = Treasury::find($oldTransaction->treasury_id);
            if ($oldTreasury) {
                $oldTreasury->decrement('balance', $oldTransaction->amount);
            }
            $oldTransaction->delete();
        }

        $this->createTreasuryTransaction($revenue);
    }
}