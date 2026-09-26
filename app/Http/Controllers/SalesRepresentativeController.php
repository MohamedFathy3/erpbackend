<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\SalesRepresentativeRequest;
use App\Http\Resources\SalesRepresentativeResource;
use App\Interfaces\SalesRepresentativeRepositoryInterface;
use App\Models\SalesRepresentative;
use App\Models\SalesInvoice;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SalesRepresentativeController extends BaseController
{

    protected mixed $crudRepository;

    public function __construct(SalesRepresentativeRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    public function login(Request $request): \Illuminate\Http\JsonResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $representative = SalesRepresentative::query()
            ->where('email', $credentials['identifier'])
            ->orWhere('phone', $credentials['identifier'])
            ->first();

        if (!$representative || !$representative->active || !Hash::check($credentials['password'], $representative->password)) {
            return response()->json(['message' => 'بيانات دخول المندوب غير صحيحة أو الحساب غير مفعل'], 401);
        }

        $token = $representative->createToken('sales-representative')->plainTextToken;

        return response()->json([
            'data' => new SalesRepresentativeResource($representative),
            'token' => $token,
        ]);
    }

    public function me(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => new SalesRepresentativeResource($request->user())]);
    }

    public function index(Request $request)
    {
        try {
            $from = $request->input('from', data_get($request->input('filters', []), 'date_from'));
            $to = $request->input('to', data_get($request->input('filters', []), 'date_to'));
            $representatives = collect($this->crudRepository->all([], [], ['*']));
            $rows = $representatives->map(function ($representative) use ($from, $to) {
                $invoices = SalesInvoice::with(['customer:id,name', 'items.product:id,name,cost'])
                    ->where('sales_representative_id', $representative->id)
                    ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))
                    ->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))
                    ->latest('invoice_date')->get();
                $sales = (float) $invoices->sum(fn ($invoice) => $invoice->net_total ?? $invoice->total_amount ?? 0);
                $cost = (float) $invoices->sum(fn ($invoice) => $invoice->items->sum(fn ($item) => (float) ($item->product?->cost ?? 0) * (float) $item->quantity));
                $rate = (float) ($representative->commission_rate ?? 0);
                $invoiceRows = $invoices->map(function ($invoice) {
                    $invoiceCost = (float) $invoice->items->sum(fn ($item) => (float) ($item->product?->cost ?? 0) * (float) $item->quantity);
                    $total = (float) ($invoice->net_total ?? $invoice->total_amount ?? 0);
                    return ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'invoice_date' => $invoice->invoice_date ?? $invoice->created_at, 'customer' => $invoice->customer?->only(['id','name','name_ar']), 'total' => round($total, 2), 'cost' => round($invoiceCost, 2), 'profit' => round($total - $invoiceCost, 2)];
                })->values();
                return array_merge((new SalesRepresentativeResource($representative))->resolve(), ['report' => ['from' => $from, 'to' => $to, 'invoice_count' => $invoices->count(), 'sales_total' => round($sales, 2), 'cost_total' => round($cost, 2), 'profit_total' => round($sales - $cost, 2), 'commission_rate' => $rate, 'commission_total' => round($sales * $rate / 100, 2), 'invoices' => $invoiceRows]]);
            });
            return response()->json(['data' => $rows->values(), 'result' => 'Success', 'message' => 'Success', 'status' => 200]);
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function store(SalesRepresentativeRequest $request)
    {
        try {
            $data = $request->validated();
            $data['password'] = Hash::make($data['password']);
            $salesRepresentative = $this->crudRepository->create($data);
            return new SalesRepresentativeResource($salesRepresentative);
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function show(SalesRepresentative $salesRepresentative): ?\Illuminate\Http\JsonResponse
    {
        try {
            return JsonResponse::respondSuccess('Item Fetched Successfully', new SalesRepresentativeResource($salesRepresentative));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function update(SalesRepresentativeRequest $request, SalesRepresentative $salesRepresentative)
    {
        try {
            $data = $request->validated();
            if (!empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }
            $this->crudRepository->update($data, $salesRepresentative->id);
            activity()->performedOn($salesRepresentative)->withProperties(['attributes' => $salesRepresentative])->log('update');
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_UPDATED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function destroy(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecords('sales_representatives', $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function restore(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->restoreItem(SalesRepresentative::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_RESTORED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }




    public function forceDelete(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecordsFinial(SalesRepresentative::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_FORCE_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

}
