<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\SalesRepresentativeRequest;
use App\Http\Resources\SalesRepresentativeResource;
use App\Interfaces\SalesRepresentativeRepositoryInterface;
use App\Models\SalesRepresentative;
use App\Models\Employee;
use App\Models\SalesInvoice;
use App\Models\Invoice;
use App\Models\EmployeeBonus;
use App\Models\Finance;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            $user = $request->user();
            $branchId = $user instanceof Employee && $user->branch_id ? (int) $user->branch_id : (int) data_get($request->input('filters', []), 'branch_id', $request->input('branch_id', 0));
            $representatives = collect($this->crudRepository->all($branchId ? ['branch_id' => $branchId] : [], [], ['*']));
            $rows = $representatives->map(function ($representative) use ($from, $to) {
                $salesInvoices = SalesInvoice::with(['customer:id,name', 'items.product:id,name,cost'])
                    ->where('sales_representative_id', $representative->id)
                    ->when($from, fn ($query) => $query->whereDate('invoice_date', '>=', $from))
                    ->when($to, fn ($query) => $query->whereDate('invoice_date', '<=', $to))->get();
                $posInvoices = Invoice::with(['customer:id,name', 'items.product:id,name,cost'])
                    ->where('sales_representative_id', $representative->id)
                    ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
                    ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))->get();
                $invoices = $salesInvoices->concat($posInvoices)->sortByDesc(fn ($invoice) => $invoice->invoice_date ?? $invoice->created_at)->values();
                $rowsForReport = $invoices->map(function ($invoice) {
                    $invoiceCost = (float) $invoice->items->sum(fn ($item) => (float) ($item->product?->cost ?? 0) * (float) $item->quantity);
                    $total = (float) ($invoice->net_total ?? $invoice->total_amount ?? $invoice->total ?? 0);
                    return ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'invoice_date' => $invoice->invoice_date ?? $invoice->created_at, 'source' => $invoice instanceof Invoice ? 'pos' : 'sales', 'customer' => $invoice->customer?->only(['id', 'name']), 'total' => round($total, 2), 'cost' => round($invoiceCost, 2), 'profit' => round($total - $invoiceCost, 2), 'commission' => round($total * ((float) ($representative->commission_rate ?? 0)) / 100, 2), 'journal_entry_id' => $invoice->journal_entry_id, 'cogs_journal_entry_id' => $invoice->cogs_journal_entry_id, 'commission_journal_entry_id' => $invoice->commission_journal_entry_id, 'items' => $invoice->items->map(fn ($item) => ['product_id' => $item->product_id, 'product_name' => $item->product?->name, 'quantity' => (float) $item->quantity, 'price' => (float) ($item->price ?? 0), 'cost' => round((float) ($item->product?->cost ?? 0), 2), 'total' => round((float) ($item->total ?? (($item->price ?? 0) * $item->quantity)), 2)])->values()];
                })->values();
                $sales = (float) $rowsForReport->sum('total');
                $cost = (float) $rowsForReport->sum('cost');
                $rate = (float) ($representative->commission_rate ?? 0);
                $bonus = $representative->employee_id
                    ? EmployeeBonus::with(['treasury', 'journalEntry'])->where('employee_id', $representative->employee_id)->when($from, fn ($q) => $q->whereDate('bonus_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('bonus_date', '<=', $to))->latest('bonus_date')->get()
                    : collect();
                return array_merge((new SalesRepresentativeResource($representative))->resolve(), ['bonus' => ['total' => round((float) $bonus->sum('amount'), 2), 'paid_total' => round((float) $bonus->where('status', 'paid')->sum('amount'), 2), 'due_total' => round((float) $bonus->where('status', 'due')->sum('amount'), 2), 'payments' => $bonus->map(fn ($item) => ['id' => $item->id, 'date' => $item->bonus_date?->toDateString(), 'amount' => (float) $item->amount, 'status' => $item->status, 'paid_at' => $item->paid_at?->toDateTimeString(), 'paid_by' => $item->recorded_by_name, 'treasury' => $item->treasury?->only(['id', 'name']), 'journal_entry_id' => $item->journal_entry_id])->values()], 'report' => ['from' => $from, 'to' => $to, 'invoice_count' => $rowsForReport->count(), 'sales_total' => round($sales, 2), 'cost_total' => round($cost, 2), 'profit_total' => round($sales - $cost, 2), 'commission_rate' => $rate, 'commission_total' => round($sales * $rate / 100, 2), 'invoices' => $rowsForReport]]);
            });
            return response()->json(['data' => $rows->values(), 'result' => 'Success', 'message' => 'Success', 'status' => 200]);
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }
    public function collectBonus(Request $request, SalesRepresentative $salesRepresentative): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['bonus_date' => 'required|date', 'treasury_id' => 'required|exists:treasuries,id', 'notes' => 'nullable|string']);
        if (!$salesRepresentative->employee_id) throw ValidationException::withMessages(['sales_representative' => 'المندوب غير مربوط بموظف.']);
        $date = $data['bonus_date'];
        $sales = SalesInvoice::where('sales_representative_id', $salesRepresentative->id)->whereDate('invoice_date', $date)->get()->sum(fn ($invoice) => (float) ($invoice->net_total ?? $invoice->total_amount ?? $invoice->total ?? 0));
        $sales += Invoice::where('sales_representative_id', $salesRepresentative->id)->whereDate('created_at', $date)->get()->sum(fn ($invoice) => (float) ($invoice->total_amount ?? $invoice->total ?? 0));
        $amount = round($sales * ((float) $salesRepresentative->commission_rate) / 100, 2);
        if ($amount <= 0) throw ValidationException::withMessages(['bonus' => 'لا توجد عمولة مستحقة لهذا المندوب في هذا اليوم.']);
        $result = DB::transaction(function () use ($data, $salesRepresentative, $date, $amount, $request) {
            $bonus = EmployeeBonus::query()->lockForUpdate()->firstOrCreate(['employee_id' => $salesRepresentative->employee_id, 'bonus_date' => $date, 'reason' => 'عمولة مبيعات المندوب'], ['amount' => $amount, 'status' => 'due', 'recorded_by_name' => $this->actor($request)]);
            if ($bonus->status === 'paid' || $bonus->finance_id) throw ValidationException::withMessages(['bonus' => 'تم دفع بونص هذا اليوم بالفعل.']);
            $treasury = Treasury::query()->lockForUpdate()->findOrFail($data['treasury_id']);
            if ((float) $treasury->balance < $amount) throw ValidationException::withMessages(['treasury_id' => 'رصيد الخزينة غير كافٍ.']);
            $finance = Finance::create(['category' => 'bonus', 'amount' => $amount, 'description' => 'عمولة مندوب المبيعات: ' . $salesRepresentative->name . ' - ' . $date, 'date' => $date, 'payment_method' => 'cash', 'treasury_id' => $treasury->id, 'branch_id' => $salesRepresentative->branch_id]);
            $treasury->decrement('balance', $amount);
            TreasuryTransaction::create(['treasury_id' => $treasury->id, 'reference_type' => EmployeeBonus::class, 'reference_id' => $bonus->id, 'type' => 'out', 'amount' => $amount, 'description' => 'دفع عمولة مندوب ' . $salesRepresentative->name]);
            $journal = app(\App\Services\AccountingAutoPostingService::class)->postFinance($finance);
            $bonus->update(['amount' => $amount, 'status' => 'paid', 'treasury_id' => $treasury->id, 'finance_id' => $finance->id, 'journal_entry_id' => $journal->id, 'paid_at' => now(), 'notes' => $data['notes'] ?? null, 'recorded_by_name' => $this->actor($request)]);
            return $bonus->fresh(['employee', 'treasury', 'finance', 'journalEntry']);
        });
        return response()->json(['status' => true, 'message' => 'تم دفع بونص المندوب وتسجيل القيد.', 'data' => $result]);
    }
    private function actor(Request $request): string { return (string) ($request->user()?->name ?? $request->user()?->email ?? 'System'); }
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
