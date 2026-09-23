<?php
namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvancePayment;
use App\Models\EmployeeFinancialTransaction;
use App\Models\EmployeePayroll;
use App\Models\Finance;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeFinancialReportsController extends Controller
{
    private function actor(Request $request): string { return (string)($request->user()?->name ?? $request->user()?->email ?? 'System'); }
    private function dates(Request $request): array { return [$request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : null, $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : null]; }

    private function createExpense(string $description, float $amount, string $date, int $treasuryId, string $referenceType, int $referenceId): Finance
    {
        $treasury = Treasury::query()->lockForUpdate()->findOrFail($treasuryId);
        if ((float)$treasury->balance < $amount) throw ValidationException::withMessages(['treasury_id' => 'رصيد الخزينة غير كافٍ لتنفيذ عملية الصرف.']);
        $finance = Finance::create(['category'=>'salaries','amount'=>$amount,'description'=>$description,'date'=>$date,'payment_method'=>'cash','treasury_id'=>$treasuryId]);
        $treasury->decrement('balance', $amount);
        TreasuryTransaction::create(['treasury_id'=>$treasuryId,'reference_type'=>$referenceType,'reference_id'=>$referenceId,'type'=>'out','amount'=>$amount,'description'=>$description]);
        // Keep the general expense ledger and the source record connected.
        return $finance;
    }

    public function index(Request $request)
    {
        [$from, $to] = $this->dates($request); $employeeId = $request->input('employee_id');
        $employees = Employee::query()->select('id','name','name_ar','employee_code','salary')->orderBy('name')->get();
        $treasuries = Treasury::query()->select('id','name','balance','currency')->orderBy('name')->get();
        $payrolls = EmployeePayroll::with(['employee','treasury'])->when($employeeId, fn($q) => $q->where('employee_id', $employeeId))->when($from, fn($q) => $q->where('period_end', '>=', $from->toDateString()))->when($to, fn($q) => $q->where('period_start', '<=', $to->toDateString()))->latest('period_end')->get();
        $advances = EmployeeAdvance::with(['employee','treasury'])->when($employeeId, fn($q) => $q->where('employee_id', $employeeId))->when($from, fn($q) => $q->where('advance_date', '>=', $from->toDateString()))->when($to, fn($q) => $q->where('advance_date', '<=', $to->toDateString()))->latest('advance_date')->get();
        return response()->json(['status'=>true,'data'=>['employees'=>$employees,'treasuries'=>$treasuries,'payrolls'=>$payrolls,'advances'=>$advances->map(fn($a)=>array_merge($a->toArray(),['remaining_amount'=>$a->remaining_amount])),'summary'=>['total_payrolls'=>(float)$payrolls->sum('net_salary'),'total_advances'=>(float)$advances->sum('amount'),'total_advance_paid'=>(float)$advances->sum('paid_amount'),'remaining_advances'=>(float)$advances->sum(fn($a)=>$a->remaining_amount),'due_payrolls'=>$payrolls->where('status','due')->count(),'paid_payrolls'=>$payrolls->where('status','paid')->count(),'overdue_payrolls'=>$payrolls->where('status','overdue')->count()]]]);
    }

    public function statement(Request $request, Employee $employee)
    {
        [$from, $to] = $this->dates($request);
        $payrolls = EmployeePayroll::where('employee_id',$employee->id)->when($from,fn($q)=>$q->where('period_end','>=',$from->toDateString()))->when($to,fn($q)=>$q->where('period_start','<=',$to->toDateString()))->get();
        $advances = EmployeeAdvance::where('employee_id',$employee->id)->with('payments')->when($from,fn($q)=>$q->where('advance_date','>=',$from->toDateString()))->when($to,fn($q)=>$q->where('advance_date','<=',$to->toDateString()))->get();
        $transactions = EmployeeFinancialTransaction::where('employee_id',$employee->id)->when($from,fn($q)=>$q->where('transaction_date','>=',$from->toDateString()))->when($to,fn($q)=>$q->where('transaction_date','<=',$to->toDateString()))->get(); $rows=[];
        foreach ($payrolls as $p) $rows[]=['date'=>optional($p->paid_at ?? $p->period_end)->toDateString(),'type'=>'salary','reason'=>'Salary '.$p->period_start.' - '.$p->period_end,'amount'=>(float)$p->net_salary,'direction'=>'credit','notes'=>$p->notes,'reference_id'=>$p->id];
        foreach ($advances as $a) { $rows[]=['date'=>$a->advance_date?->toDateString(),'type'=>'advance','reason'=>$a->reason,'amount'=>(float)$a->amount,'direction'=>'debit','notes'=>$a->notes,'reference_id'=>$a->id]; foreach ($a->payments as $p) $rows[]=['date'=>$p->payment_date?->toDateString(),'type'=>'advance_payment','reason'=>'Advance repayment: '.$a->reason,'amount'=>(float)$p->amount,'direction'=>'credit','notes'=>$p->notes,'reference_id'=>$p->id]; }
        foreach ($transactions as $t) $rows[]=['date'=>$t->transaction_date?->toDateString(),'type'=>$t->type,'reason'=>$t->reason,'amount'=>(float)$t->amount,'direction'=>in_array($t->type,['deduction','other_debit'])?'debit':'credit','notes'=>$t->notes,'reference_id'=>$t->id];
        usort($rows,fn($a,$b)=>strcmp($a['date']??'',$b['date']??'')); $balance=0; foreach($rows as &$row){$balance += $row['direction']==='credit'?$row['amount']:-$row['amount'];$row['balance_after']=$balance;} unset($row);
        return response()->json(['status'=>true,'data'=>['employee'=>$employee->only(['id','name','name_ar','employee_code','salary']),'summary'=>['salary_total'=>(float)$payrolls->sum('net_salary'),'advances_total'=>(float)$advances->sum('amount'),'advances_remaining'=>(float)$advances->sum(fn($a)=>$a->remaining_amount),'balance'=>$balance],'transactions'=>$rows]]);
    }

    public function storePayroll(Request $request)
    {
        $data=$request->validate(['employee_id'=>'required|exists:employees,id','treasury_id'=>'nullable|exists:treasuries,id','period_start'=>'required|date','period_end'=>'required|date|after_or_equal:period_start','base_salary'=>'required|numeric|min:0','allowances'=>'nullable|numeric|min:0','deductions'=>'nullable|numeric|min:0','advance_deductions'=>'nullable|numeric|min:0','due_date'=>'nullable|date','status'=>'nullable|in:due,paid,overdue','paid_at'=>'nullable|date','adjustments'=>'nullable|array','notes'=>'nullable|string']);
        foreach(['allowances','deductions','advance_deductions'] as $key) $data[$key]=(float)($data[$key]??0); $data['net_salary']=max(0,(float)$data['base_salary']+$data['allowances']-$data['deductions']-$data['advance_deductions']);
        if (($data['status']??'due')==='paid' && empty($data['treasury_id'])) throw ValidationException::withMessages(['treasury_id'=>'اختيار الخزينة مطلوب عند صرف الراتب.']);
        $payroll=DB::transaction(function() use($data){$payroll=EmployeePayroll::create($data); if($payroll->status==='paid'){$finance=$this->createExpense('صرف راتب الموظف: '.$payroll->employee()->value('name'),(float)$payroll->net_salary,($payroll->paid_at?->toDateString()??$payroll->period_end->toDateString()),(int)$payroll->treasury_id,EmployeePayroll::class,$payroll->id);$payroll->update(['finance_id'=>$finance->id]);}return $payroll->fresh(['employee','treasury','finance']);});
        return response()->json(['status'=>true,'data'=>$payroll],201);
    }

    public function updatePayroll(Request $request, EmployeePayroll $payroll)
    {
        $data=$request->validate(['treasury_id'=>'nullable|exists:treasuries,id','base_salary'=>'sometimes|numeric|min:0','allowances'=>'nullable|numeric|min:0','deductions'=>'nullable|numeric|min:0','advance_deductions'=>'nullable|numeric|min:0','due_date'=>'nullable|date','status'=>'nullable|in:due,paid,overdue','paid_at'=>'nullable|date','adjustments'=>'nullable|array','notes'=>'nullable|string']); $merged=array_merge($payroll->toArray(),$data); $data['net_salary']=max(0,(float)$merged['base_salary']+(float)($merged['allowances']??0)-(float)($merged['deductions']??0)-(float)($merged['advance_deductions']??0));
        DB::transaction(function() use($data,$payroll){$payroll->update($data); if($payroll->status==='paid'&&!$payroll->finance_id){if(!$payroll->treasury_id)throw ValidationException::withMessages(['treasury_id'=>'اختيار الخزينة مطلوب عند صرف الراتب.']);$finance=$this->createExpense('صرف راتب الموظف: '.$payroll->employee()->value('name'),(float)$payroll->net_salary,($payroll->paid_at?->toDateString()??$payroll->period_end->toDateString()),(int)$payroll->treasury_id,EmployeePayroll::class,$payroll->id);$payroll->update(['finance_id'=>$finance->id]);}}); return response()->json(['status'=>true,'data'=>$payroll->fresh(['employee','treasury','finance'])]);
    }

    public function storeAdvance(Request $request)
    { $data=$request->validate(['employee_id'=>'required|exists:employees,id','treasury_id'=>'required|exists:treasuries,id','amount'=>'required|numeric|gt:0','advance_date'=>'required|date','reason'=>'required|string','notes'=>'nullable|string']); $data['recorded_by_name']=$this->actor($request); $advance=DB::transaction(function() use($data){$advance=EmployeeAdvance::create($data);$finance=$this->createExpense('صرف سلفة للموظف: '.$advance->employee()->value('name').' - '.$advance->reason,(float)$advance->amount,$advance->advance_date->toDateString(),(int)$advance->treasury_id,EmployeeAdvance::class,$advance->id);$advance->update(['finance_id'=>$finance->id]);return $advance->fresh(['employee','treasury','finance']);}); return response()->json(['status'=>true,'data'=>$advance],201); }

    public function storeAdvancePayment(Request $request, EmployeeAdvance $advance)
    { $data=$request->validate(['treasury_id'=>'required|exists:treasuries,id','amount'=>'required|numeric|gt:0','payment_date'=>'required|date','notes'=>'nullable|string']); if((float)$data['amount']>$advance->remaining_amount)return response()->json(['message'=>'Payment exceeds remaining advance amount'],422); $data['advance_id']=$advance->id;$data['recorded_by_name']=$this->actor($request);$payment=DB::transaction(function()use($data,$advance){$payment=EmployeeAdvancePayment::create($data);$treasury=Treasury::query()->lockForUpdate()->findOrFail($data['treasury_id']);$treasury->increment('balance',$data['amount']);TreasuryTransaction::create(['treasury_id'=>$treasury->id,'reference_type'=>EmployeeAdvancePayment::class,'reference_id'=>$payment->id,'type'=>'in','amount'=>$data['amount'],'description'=>'سداد سلفة الموظف: '.$advance->employee()->value('name')]);$newPaid=(float)$advance->paid_amount+(float)$data['amount'];$advance->update(['paid_amount'=>$newPaid,'last_payment_at'=>$data['payment_date'],'status'=>$newPaid >= (float)$advance->amount?'paid':'repaying']);return $payment->fresh('treasury');}); return response()->json(['status'=>true,'data'=>$payment],201); }

    public function storeTransaction(Request $request)
    { $data=$request->validate(['employee_id'=>'required|exists:employees,id','transaction_date'=>'required|date','type'=>'required|in:allowance,deduction,other_credit,other_debit','reason'=>'required|string','amount'=>'required|numeric|gt:0','notes'=>'nullable|string']); $data['recorded_by_name']=$this->actor($request); return response()->json(['status'=>true,'data'=>EmployeeFinancialTransaction::create($data)],201); }
}
