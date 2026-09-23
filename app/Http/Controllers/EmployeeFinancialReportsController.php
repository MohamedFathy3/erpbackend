<?php
namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvancePayment;
use App\Models\EmployeeFinancialTransaction;
use App\Models\EmployeePayroll;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeFinancialReportsController extends Controller
{
    private function actor(Request $request): string { return (string)($request->user()?->name ?? $request->user()?->email ?? 'System'); }
    private function dates(Request $request): array {
        return [$request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : null, $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : null];
    }
    public function index(Request $request)
    {
        [$from, $to] = $this->dates($request); $employeeId = $request->input('employee_id');
        $employees = Employee::query()->select('id','name','name_ar','employee_code','salary')->orderBy('name')->get();
        $payrollQuery = EmployeePayroll::with('employee')->when($employeeId, fn($q) => $q->where('employee_id', $employeeId))->when($from, fn($q) => $q->where('period_end', '>=', $from->toDateString()))->when($to, fn($q) => $q->where('period_start', '<=', $to->toDateString()))->latest('period_end');
        $advanceQuery = EmployeeAdvance::with('employee')->when($employeeId, fn($q) => $q->where('employee_id', $employeeId))->when($from, fn($q) => $q->where('advance_date', '>=', $from->toDateString()))->when($to, fn($q) => $q->where('advance_date', '<=', $to->toDateString()))->latest('advance_date');
        $payrolls = $payrollQuery->get(); $advances = $advanceQuery->get();
        $totalPayroll = (float)$payrolls->sum('net_salary'); $totalAdvances = (float)$advances->sum('amount'); $remainingAdvances = (float)$advances->sum(fn($a) => $a->remaining_amount);
        return response()->json(['status'=>true,'data'=>[
            'employees'=>$employees, 'payrolls'=>$payrolls, 'advances'=>$advances->map(fn($a) => array_merge($a->toArray(), ['remaining_amount'=>$a->remaining_amount])),
            'summary'=>['total_payrolls'=>$totalPayroll,'total_advances'=>$totalAdvances,'total_advance_paid'=>(float)$advances->sum('paid_amount'),'remaining_advances'=>$remainingAdvances,'due_payrolls'=>$payrolls->where('status','due')->count(),'paid_payrolls'=>$payrolls->where('status','paid')->count(),'overdue_payrolls'=>$payrolls->where('status','overdue')->count()]
        ]]);
    }
    public function statement(Request $request, Employee $employee)
    {
        [$from, $to] = $this->dates($request);
        $payrolls = EmployeePayroll::where('employee_id',$employee->id)->when($from,fn($q)=>$q->where('period_end','>=',$from->toDateString()))->when($to,fn($q)=>$q->where('period_start','<=',$to->toDateString()))->get();
        $advances = EmployeeAdvance::where('employee_id',$employee->id)->with('payments')->when($from,fn($q)=>$q->where('advance_date','>=',$from->toDateString()))->when($to,fn($q)=>$q->where('advance_date','<=',$to->toDateString()))->get();
        $transactions = EmployeeFinancialTransaction::where('employee_id',$employee->id)->when($from,fn($q)=>$q->where('transaction_date','>=',$from->toDateString()))->when($to,fn($q)=>$q->where('transaction_date','<=',$to->toDateString()))->get();
        $rows=[];
        foreach ($payrolls as $p) $rows[]=['date'=>optional($p->paid_at ?? $p->period_end)->toDateString(),'type'=>'salary','reason'=>'Salary '.$p->period_start.' - '.$p->period_end,'amount'=>(float)$p->net_salary,'direction'=>'credit','notes'=>$p->notes,'reference_id'=>$p->id];
        foreach ($advances as $a) { $rows[]=['date'=>$a->advance_date?->toDateString(),'type'=>'advance','reason'=>$a->reason,'amount'=>(float)$a->amount,'direction'=>'debit','notes'=>$a->notes,'reference_id'=>$a->id]; foreach ($a->payments as $p) $rows[]=['date'=>$p->payment_date?->toDateString(),'type'=>'advance_payment','reason'=>'Advance repayment: '.$a->reason,'amount'=>(float)$p->amount,'direction'=>'credit','notes'=>$p->notes,'reference_id'=>$p->id]; }
        foreach ($transactions as $t) $rows[]=['date'=>$t->transaction_date?->toDateString(),'type'=>$t->type,'reason'=>$t->reason,'amount'=>(float)$t->amount,'direction'=>in_array($t->type,['deduction','other_debit'])?'debit':'credit','notes'=>$t->notes,'reference_id'=>$t->id];
        usort($rows, fn($a,$b)=>strcmp($a['date'] ?? '', $b['date'] ?? '')); $balance=0; foreach ($rows as &$row) { $balance += $row['direction']==='credit' ? $row['amount'] : -$row['amount']; $row['balance_after']=$balance; } unset($row);
        return response()->json(['status'=>true,'data'=>['employee'=>$employee->only(['id','name','name_ar','employee_code','salary']),'summary'=>['salary_total'=>(float)$payrolls->sum('net_salary'),'advances_total'=>(float)$advances->sum('amount'),'advances_remaining'=>(float)$advances->sum(fn($a)=>$a->remaining_amount),'balance'=>$balance],'transactions'=>$rows]]);
    }
    public function storePayroll(Request $request)
    {
        $data=$request->validate(['employee_id'=>'required|exists:employees,id','period_start'=>'required|date','period_end'=>'required|date|after_or_equal:period_start','base_salary'=>'required|numeric|min:0','allowances'=>'nullable|numeric|min:0','deductions'=>'nullable|numeric|min:0','advance_deductions'=>'nullable|numeric|min:0','due_date'=>'nullable|date','status'=>'nullable|in:due,paid,overdue','paid_at'=>'nullable|date','adjustments'=>'nullable|array','notes'=>'nullable|string']);
        foreach(['allowances','deductions','advance_deductions'] as $key) $data[$key]=(float)($data[$key]??0); $data['net_salary']=max(0,(float)$data['base_salary']+$data['allowances']-$data['deductions']-$data['advance_deductions']); return response()->json(['status'=>true,'data'=>EmployeePayroll::create($data)],201);
    }
    public function updatePayroll(Request $request, EmployeePayroll $payroll)
    {
        $data=$request->validate(['base_salary'=>'sometimes|numeric|min:0','allowances'=>'nullable|numeric|min:0','deductions'=>'nullable|numeric|min:0','advance_deductions'=>'nullable|numeric|min:0','due_date'=>'nullable|date','status'=>'nullable|in:due,paid,overdue','paid_at'=>'nullable|date','adjustments'=>'nullable|array','notes'=>'nullable|string']); $merged=array_merge($payroll->toArray(),$data); $data['net_salary']=max(0,(float)$merged['base_salary']+(float)($merged['allowances']??0)-(float)($merged['deductions']??0)-(float)($merged['advance_deductions']??0)); $payroll->update($data); return response()->json(['status'=>true,'data'=>$payroll->fresh()]);
    }
    public function storeAdvance(Request $request)
    { $data=$request->validate(['employee_id'=>'required|exists:employees,id','amount'=>'required|numeric|gt:0','advance_date'=>'required|date','reason'=>'required|string','notes'=>'nullable|string']); $data['recorded_by_name']=$this->actor($request); return response()->json(['status'=>true,'data'=>EmployeeAdvance::create($data)],201); }
    public function storeAdvancePayment(Request $request, EmployeeAdvance $advance)
    { $data=$request->validate(['amount'=>'required|numeric|gt:0','payment_date'=>'required|date','notes'=>'nullable|string']); if ((float)$data['amount'] > $advance->remaining_amount) return response()->json(['message'=>'Payment exceeds remaining advance amount'],422); $data['advance_id']=$advance->id; $data['recorded_by_name']=$this->actor($request); $payment=DB::transaction(function() use($data,$advance){ $payment=EmployeeAdvancePayment::create($data); $newPaid=(float)$advance->paid_amount+(float)$data['amount']; $advance->update(['paid_amount'=>$newPaid,'last_payment_at'=>$data['payment_date'],'status'=>$newPaid >= (float)$advance->amount ? 'paid' : 'repaying']); return $payment; }); return response()->json(['status'=>true,'data'=>$payment],201); }
    public function storeTransaction(Request $request)
    { $data=$request->validate(['employee_id'=>'required|exists:employees,id','transaction_date'=>'required|date','type'=>'required|in:allowance,deduction,other_credit,other_debit','reason'=>'required|string','amount'=>'required|numeric|gt:0','notes'=>'nullable|string']); $data['recorded_by_name']=$this->actor($request); return response()->json(['status'=>true,'data'=>EmployeeFinancialTransaction::create($data)],201); }
}
