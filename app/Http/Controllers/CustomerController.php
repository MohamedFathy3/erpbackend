<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Imports\CustomerImport;
use App\Interfaces\CustomerRepositoryInterface;
use App\Models\Customer;
use Exception;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CustomerController extends BaseController
{

    protected mixed $crudRepository;

    public function __construct(CustomerRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    public function index()
    {
        try {
            $customer = CustomerResource::collection($this->crudRepository->all(
                [],
                [],
                ['*']
            ));
            return $customer->additional(JsonResponse::success());
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function store(CustomerRequest $request)
    {
        try {
            $customer = $this->crudRepository->create($request->validated());
            return new CustomerResource($customer);
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function show(Customer $customer): ?\Illuminate\Http\JsonResponse
    {
        try {
            return JsonResponse::respondSuccess('Item Fetched Successfully', new CustomerResource($customer));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    /**
     * Unified customer statement: POS invoices, sales invoices, payments and balance.
     */
    public function statement(Customer $customer): \Illuminate\Http\JsonResponse
    {
        try {
            $posInvoices = $customer->invoices()->with('payments')->latest()->get()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'source' => 'pos',
                'type' => 'sale',
                'number' => $invoice->invoice_number,
                'date' => $invoice->created_at?->toDateString(),
                'total' => (float) ($invoice->total_amount ?? 0),
                'paid' => (float) ($invoice->paid_amount ?? 0),
                'due' => (float) ($invoice->remaining_amount ?? 0),
                'status' => $invoice->status,
                'payments' => $invoice->payments->map(fn ($payment) => [
                    'method' => $payment->method,
                    'amount' => (float) $payment->amount,
                    'date' => $payment->created_at?->toDateString(),
                ])->values(),
            ]);

            $salesInvoices = $customer->salesInvoices()->latest('invoice_date')->get()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'source' => 'sales',
                'type' => 'sale',
                'number' => $invoice->invoice_number,
                'date' => ($invoice->invoice_date ?? $invoice->created_at)?->toDateString(),
                'total' => (float) ($invoice->net_total ?? $invoice->total_amount ?? 0),
                'paid' => (float) ($invoice->paid_amount ?? 0),
                'due' => max(0, (float) ($invoice->net_total ?? $invoice->total_amount ?? 0) - (float) ($invoice->paid_amount ?? 0)),
                'status' => $invoice->status ?? ((float) ($invoice->paid_amount ?? 0) > 0 ? 'partial' : 'unpaid'),
                'payments' => [],
            ]);

            $salesReturns = $customer->salesReturns()->latest('created_at')->get()->map(fn ($return) => [
                'id' => $return->id,
                'source' => 'sales_return',
                'type' => 'return',
                'number' => $return->return_number,
                'date' => $return->created_at?->toDateString(),
                'total' => -((float) ($return->total_amount ?? 0)),
                'paid' => -((float) ($return->total_amount ?? 0)),
                'due' => 0,
                'status' => 'returned',
                'payments' => [],
            ]);

            $transactions = $posInvoices->concat($salesInvoices)->concat($salesReturns)->sortByDesc('date')->values();
            $total = $transactions->sum('total');
            $paid = $transactions->sum('paid');

            return response()->json([
                'status' => true,
                'data' => [
                    'customer' => new CustomerResource($customer),
                    'summary' => [
                        'transactions_count' => $transactions->count(),
                        'total_purchases' => (float) $total,
                        'total_paid' => (float) $paid,
                        'outstanding_balance' => (float) ($total - $paid),
                        'credit_limit' => (float) ($customer->credit_limit ?? 0),
                        'available_credit' => max(0, (float) ($customer->credit_limit ?? 0) - ($total - $paid)),
                        'last_activity_at' => $transactions->first()['date'] ?? null,
                    ],
                    'transactions' => $transactions,
                ],
            ]);
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        try {
            $this->crudRepository->update($request->validated(), $customer->id);
            activity()->performedOn($customer)->withProperties(['attributes' => $customer])->log('update');
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_UPDATED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function destroy(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecords('customers', $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function restore(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->restoreItem(Customer::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_RESTORED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }




    public function forceDelete(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecordsFinial(Customer::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_FORCE_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function importCustomers(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv'
        ]);

        try {

            Excel::import(new CustomerImport, $request->file('file'));

            return response()->json([
                'status' => true,
                'message' => 'تم استيراد الزباين بنجاح'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الاستيراد',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
