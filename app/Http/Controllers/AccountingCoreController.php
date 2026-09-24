<?php
namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\FinancialPeriod;
use App\Models\JournalEntry;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingCoreController extends Controller
{
    private function tenantId(): ?int { return auth()->user()?->tenant_id; }
    private function periodFor(string $date): ?FinancialPeriod { return FinancialPeriod::where('starts_on','<=',$date)->where('ends_on','>=',$date)->where('status','open')->first(); }
    private function postedLines(Request $request) {
        return DB::table('journal_entry_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')->where('j.status','posted')->when($request->filled('from'),fn($q)=>$q->whereDate('j.entry_date','>=',$request->from))->when($request->filled('to'),fn($q)=>$q->whereDate('j.entry_date','<=',$request->to))->when($request->filled('branch_id'),fn($q)=>$q->where('j.branch_id',$request->branch_id))->when($request->filled('cost_center_id'),fn($q)=>$q->where('l.cost_center_id',$request->cost_center_id));
    }

    public function periods() { return response()->json(['data'=>FinancialPeriod::orderByDesc('starts_on')->get()]); }
    public function storePeriod(Request $request) {
        $data=$request->validate(['code'=>'required|string|max:50','name'=>'required|string|max:150','starts_on'=>'required|date','ends_on'=>'required|date|after_or_equal:starts_on']);
        if(FinancialPeriod::where(function($q)use($data){$q->whereBetween('starts_on',[$data['starts_on'],$data['ends_on']])->orWhereBetween('ends_on',[$data['starts_on'],$data['ends_on']]);})->exists()) throw ValidationException::withMessages(['starts_on'=>'الفترة تتداخل مع فترة مالية موجودة.']);
        return response()->json(['data'=>FinancialPeriod::create($data)],201);
    }
    public function closePeriod(FinancialPeriod $period) {
        if($period->status!=='open') throw ValidationException::withMessages(['period'=>'الفترة مغلقة بالفعل.']);
        $drafts=JournalEntry::where('fiscal_period_id',$period->id)->where('status','draft')->count(); if($drafts) throw ValidationException::withMessages(['period'=>'لا يمكن إغلاق فترة تحتوي على قيود مسودة.']);
        $actor=auth()->user(); $period->update(['status'=>'closed','closed_by'=>$actor?->id,'closed_by_type'=>$actor ? $actor::class : null,'closed_at'=>now()]); return response()->json(['data'=>$period]);
    }
    public function reopenPeriod(Request $request, FinancialPeriod $period) { $request->validate(['reason'=>'required|string']); $period->update(['status'=>'open','closed_by'=>null,'closed_at'=>null,'close_notes'=>$request->reason]); return response()->json(['data'=>$period]); }

    public function costCenters() { return response()->json(['data'=>CostCenter::with('parent')->orderBy('code')->get()]); }
    public function storeCostCenter(Request $request) { $data=$request->validate(['code'=>'required|string|max:50','name'=>'required|string|max:150','name_ar'=>'nullable|string','parent_id'=>'nullable|exists:cost_centers,id','branch_id'=>'nullable|exists:branches,id']); return response()->json(['data'=>CostCenter::create($data)],201); }
    public function updateCostCenter(Request $request, CostCenter $costCenter) { $data=$request->validate(['name'=>'sometimes|string|max:150','name_ar'=>'nullable|string','parent_id'=>'nullable|exists:cost_centers,id','branch_id'=>'nullable|exists:branches,id','is_active'=>'boolean']); $costCenter->update($data); return response()->json(['data'=>$costCenter]); }

    public function ledger(Request $request, Account $account) {
        $rows=$this->postedLines($request)->where('l.account_id',$account->id)->select('j.id','j.entry_date','j.entry_number','j.description_ar','j.description_en','l.debit','l.credit','l.cost_center_id','j.source_type','j.source_id')->orderBy('j.entry_date')->orderBy('j.id')->get(); $balance=0; $rows=$rows->map(function($r)use(&$balance,$account){$balance += in_array($account->normal_balance,['credit','liability','equity','revenue']) ? ((float)$r->credit-(float)$r->debit) : ((float)$r->debit-(float)$r->credit); $r->balance=round($balance,2); return $r;}); return response()->json(['data'=>['account'=>$account,'rows'=>$rows,'balance'=>$balance]]);
    }
    public function trialBalance(Request $request) {
        $lines=$this->postedLines($request)->select('l.account_id',DB::raw('SUM(l.debit) debit'),DB::raw('SUM(l.credit) credit'))->groupBy('l.account_id')->get()->keyBy('account_id'); $accounts=Account::where('is_active',true)->orderBy('code')->get()->map(function($a)use($lines){$v=$lines->get($a->id);$a->period_debit=(float)($v->debit??0);$a->period_credit=(float)($v->credit??0);$a->debit_balance=max(0,$a->period_debit-$a->period_credit);$a->credit_balance=max(0,$a->period_credit-$a->period_debit);return $a;}); return response()->json(['data'=>['accounts'=>$accounts,'totals'=>['debit'=>(float)$accounts->sum('period_debit'),'credit'=>(float)$accounts->sum('period_credit')]]]);
    }
    public function incomeStatement(Request $request) { return $this->statementByTypes($request,['revenue','expense','income']); }
    private function statementByTypes(Request $request,array $types) { $lines=$this->postedLines($request)->join('accounts as a','a.id','=','l.account_id')->whereIn('a.account_type',$types)->select('a.id','a.code','a.name','a.name_ar','a.account_type',DB::raw('SUM(l.debit) debit'),DB::raw('SUM(l.credit) credit'))->groupBy('a.id','a.code','a.name','a.name_ar','a.account_type')->get(); $revenue=(float)$lines->where('account_type','revenue')->sum(fn($x)=>(float)$x->credit-(float)$x->debit);$expense=(float)$lines->where('account_type','expense')->sum(fn($x)=>(float)$x->debit-(float)$x->credit); return response()->json(['data'=>['lines'=>$lines,'revenue'=>$revenue,'expenses'=>$expense,'net_profit'=>$revenue-$expense]]); }
    public function balanceSheet(Request $request) { $lines=$this->postedLines($request)->join('accounts as a','a.id','=','l.account_id')->whereIn('a.account_type',['asset','liability','equity','treasury'])->select('a.id','a.code','a.name','a.name_ar','a.account_type',DB::raw('SUM(l.debit) debit'),DB::raw('SUM(l.credit) credit'))->groupBy('a.id','a.code','a.name','a.name_ar','a.account_type')->get(); return response()->json(['data'=>['lines'=>$lines,'assets'=>(float)$lines->whereIn('account_type',['asset','treasury'])->sum(fn($x)=>(float)$x->debit-(float)$x->credit),'liabilities'=>(float)$lines->where('account_type','liability')->sum(fn($x)=>(float)$x->credit-(float)$x->debit),'equity'=>(float)$lines->where('account_type','equity')->sum(fn($x)=>(float)$x->credit-(float)$x->debit)]]); }
    public function cashFlow(Request $request) { $rows=$this->postedLines($request)->whereNotNull('j.treasury_id')->select('j.id','j.entry_date','j.description_ar','j.description_en','j.treasury_id',DB::raw('SUM(l.debit) debit'),DB::raw('SUM(l.credit) credit'))->groupBy('j.id','j.entry_date','j.description_ar','j.description_en','j.treasury_id')->orderBy('j.entry_date')->get(); return response()->json(['data'=>['rows'=>$rows,'inflows'=>(float)$rows->sum('debit'),'outflows'=>(float)$rows->sum('credit'),'net'=>(float)$rows->sum('debit')-(float)$rows->sum('credit')]]); }

    public function reverse(Request $request, JournalEntry $journalEntry) {
        $data=$request->validate(['reason'=>'required|string']); if(!$journalEntry->isPosted()) throw ValidationException::withMessages(['journal'=>'لا يمكن عكس قيد غير مرحل.']); if($journalEntry->reversals()->exists()) throw ValidationException::withMessages(['journal'=>'تم عكس هذا القيد من قبل.']);
        $reversal=DB::transaction(function()use($journalEntry,$data){$journalEntry->load('lines');$reversal=JournalEntry::create(['entry_date'=>now()->toDateString(),'description_ar'=>'عكس القيد '.$journalEntry->entry_number,'description_en'=>'Reverse entry '.$journalEntry->entry_number,'notes'=>$data['reason'],'status'=>'posted','treasury_id'=>$journalEntry->treasury_id,'source_type'=>JournalEntry::class,'source_id'=>$journalEntry->id,'reversal_of_id'=>$journalEntry->id,'posted_by'=>auth()->id(),'posted_at'=>now(),'fiscal_period_id'=>$this->periodFor(now()->toDateString())?->id]); foreach($journalEntry->lines as $line)$reversal->lines()->create(['account_id'=>$line->account_id,'debit'=>$line->credit,'credit'=>$line->debit,'description'=>'Reversal: '.$line->description,'cost_center_id'=>$line->cost_center_id,'branch_id'=>$line->branch_id]); if($journalEntry->treasury_id){$treasury=Treasury::query()->lockForUpdate()->find($journalEntry->treasury_id);$account=Account::where('code','treasury_'.$journalEntry->treasury_id)->orWhere('account_type','treasury')->first();$line=$account?$reversal->lines()->where('account_id',$account->id)->first():null;if($treasury&&$line){$amount=(float)($line->debit?:$line->credit);if($line->debit)$treasury->increment('balance',$amount);else $treasury->decrement('balance',$amount);TreasuryTransaction::create(['treasury_id'=>$treasury->id,'reference_type'=>JournalEntry::class,'reference_id'=>$reversal->id,'type'=>$line->debit?'in':'out','amount'=>$amount,'description'=>'عكس القيد '.$journalEntry->entry_number]);}}$journalEntry->update(['status'=>'cancelled']);return $reversal->load('lines');}); return response()->json(['data'=>$reversal]);
    }
}
