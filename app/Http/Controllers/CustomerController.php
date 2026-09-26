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

    public function index(Request $request)
    {
        try {
            $query = Customer::query()->with('branch');
            $user = $request->user();
            $branchId = $user instanceof \App\Models\Employee && $user->branch_id
                ? (int) $user->branch_id
                : (int) ($request->input('branch_id') ?: data_get($request->input('filters', []), 'branch_id', 0));
            if ($branchId) $query->where('branch_id', $branchId);
            $customer = CustomerResource::collection($query->latest()->get());
            return $customer->additional(JsonResponse::success());
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function store(CustomerRequest $request)
    {
        try {
            $data = $request->validated();
            $user = $request->user();
            if ($user instanceof \App\Models\Employee && $user->branch_id) {
                $data['branch_id'] = $user->branch_id;
            } elseif (!$request->filled('branch_id')) {
                $data['branch_id'] = null;
            }
            $customer = $this->crudRepository->create($data);
            return new CustomerResource($customer->load('branch'));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function show(Customer $customer): ?\Illuminate\Http\JsonResponse
    {
        try {
            $user = request()->user();
            if ($user instanceof \App\Models\Employee && $user->branch_id && $customer->branch_id && (int) $customer->branch_id !== (int) $user->branch_id) {
                abort(403, 'You are not allowed to access another branch customer');
            }
            return JsonResponse::respondSuccess('Item Fetched Successfully', new CustomerResource($customer->load('branch')));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    /**
     * Unified customer statement: POS invoices, sales invoices, payments and balance.
     */
    public function statement(Request $request, Customer $customer): \Illuminate\Http\JsonResponse
    {
        try {
            $user = $request->user();
            if ($user instanceof \App\Models\Employee && $user->branch_id && $customer->branch_id && (int) $customer->branch_id !== (int) $user->branch_id) {
                abort(403, 'You are not allowed to access another branch customer');
            }
            $from = $request->input('from') ? \Illuminate\Support\Carbon::parse($request->input('from'))->startOfDay() : null;
            $to = $request->input('to') ? \Illuminate\Support\Carbon::parse($request->input('to'))->endOfDay() : null;
            $posQuery = $customer->invoices()->with(['payments.employee', 'items.product', 'branch']);
            $salesQuery = $customer->salesInvoices()->with(['payments.employee', 'items.product', 'branch']);
            if ($from) { $posQuery->where('invoices.created_at', '>=', $from); $salesQuery->where('sales_invoices.invoice_date', '>=', $from->toDateString()); }
            if ($to) { $posQuery->where('invoices.created_at', '<=', $to); $salesQuery->where('sales_invoices.invoice_date', '<=', $to->toDateString()); }
            $posInvoices = $posQuery->latest()->get()->map(fn ($invoice) => [
                'id'=>$invoice->id,'source'=>'pos','type'=>'sale','number'=>$invoice->invoice_number,'date'=>$invoice->created_at?->toDateString(),
                'branch'=>$invoice->branch?->only(['id','name','name_ar']),'total'=>(float)($invoice->total_amount??0),'paid'=>(float)($invoice->paid_amount??0),'due'=>(float)($invoice->remaining_amount??0),'status'=>$invoice->status,
                'products'=>$invoice->items->map(fn($item)=>['id'=>$item->product?->id,'name'=>$item->product?->name ?? $item->product_name,'quantity'=>(float)$item->quantity,'total'=>(float)$item->total])->values(),
                'payments'=>$invoice->payments->map(fn($payment)=>['id'=>$payment->id,'method'=>$payment->method,'amount'=>(float)$payment->amount,'date'=>$payment->created_at?->toDateString(),'employee'=>$payment->employee?->only(['id','name'])])->values(),
            ]);
            $salesInvoices = $salesQuery->latest('invoice_date')->get()->map(fn ($invoice) => [
                'id'=>$invoice->id,'source'=>'sales','type'=>'sale','number'=>$invoice->invoice_number,'date'=>($invoice->invoice_date??$invoice->created_at)?->toDateString(),
                'branch'=>$invoice->branch?->only(['id','name','name_ar']),'total'=>(float)($invoice->net_total??$invoice->total_amount??0),'paid'=>(float)($invoice->paid_amount??0),'due'=>max(0,(float)($invoice->net_total??$invoice->total_amount??0)-(float)($invoice->paid_amount??0)),
                'status'=>$invoice->payment_status ?? 'unpaid','products'=>$invoice->items->map(fn($item)=>['id'=>$item->product?->id,'name'=>$item->product?->name ?? $item->product_name,'quantity'=>(float)$item->quantity,'total'=>(float)$item->total])->values(),
                'payments'=>$invoice->payments->map(fn($payment)=>['id'=>$payment->id,'method'=>$payment->payment_method,'amount'=>(float)$payment->amount,'date'=>$payment->created_at?->toDateString(),'employee'=>$payment->employee?->only(['id','name'])])->values(),
            ]);
            $returns = $customer->salesReturns()->with(['invoice.branch'])->when($from, fn($q)=>$q->where('created_at','>=',$from))->when($to, fn($q)=>$q->where('created_at','<=',$to))->latest()->get()->map(fn($return)=>[
                'id'=>$return->id,'source'=>'sales_return','type'=>'return','number'=>$return->return_number,'date'=>$return->created_at?->toDateString(),'branch'=>$return->invoice?->branch?->only(['id','name','name_ar']),'total'=>-((float)($return->total_amount??0)),'paid'=>-((float)($return->total_amount??0)),'due'=>0,'status'=>'returned','products'=>[],'payments'=>[],
            ]);
            $transactions = $posInvoices->concat($salesInvoices)->concat($returns)->sortByDesc('date')->values();
            $total=(float)$transactions->sum('total'); $paid=(float)$transactions->sum('paid');
            $branch = $customer->branch?->only(['id','name','name_ar']) ?: $transactions->first(fn($t)=>!empty($t['branch']))['branch'] ?? null;
            return response()->json(['status'=>true,'data'=>['customer'=>array_merge((new CustomerResource($customer->load('branch')))->resolve(), ['branch'=>$branch]),'branch'=>$branch,'summary'=>['transactions_count'=>$transactions->count(),'total_purchases'=>$total,'total_paid'=>$paid,'outstanding_balance'=>$total-$paid,'credit_limit'=>(float)($customer->credit_limit??0),'available_credit'=>max(0,(float)($customer->credit_limit??0)-($total-$paid)),'collections_total'=>$transactions->flatMap(fn($t)=>$t['payments'])->sum('amount'),'last_activity_at'=>$transactions->first()['date']??null],'transactions'=>$transactions]]);
        } catch (Exception $e) { return JsonResponse::respondError($e->getMessage()); }
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        try {
            $user = $request->user();
            if ($user instanceof \App\Models\Employee && $user->branch_id && $customer->branch_id && (int) $customer->branch_id !== (int) $user->branch_id) {
                abort(403, 'You are not allowed to update another branch customer');
            }
            $data = $request->validated();
            if ($user instanceof \App\Models\Employee && $user->branch_id) $data['branch_id'] = $user->branch_id;
            $this->crudRepository->update($data, $customer->id);
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
