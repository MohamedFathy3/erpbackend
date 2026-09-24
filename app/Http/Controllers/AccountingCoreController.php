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
    private function postedLines(Request $request, bool $ignoreDateFilters = false) {
        return DB::table('journal_entry_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')
            ->where('j.status','posted')
            ->when($this->tenantId() !== null, fn($q) => $q->where('j.tenant_id', $this->tenantId()))
            ->when(!$ignoreDateFilters && $request->filled('from'), fn($q) => $q->whereDate('j.entry_date', '>=', $request->input('from')))
            ->when(!$ignoreDateFilters && $request->filled('to'), fn($q) => $q->whereDate('j.entry_date', '<=', $request->input('to')))
            ->when($request->filled('branch_id'), fn($q) => $q->where('j.branch_id', $request->input('branch_id')))
            ->when($request->filled('cost_center_id'), fn($q) => $q->where('l.cost_center_id', $request->input('cost_center_id')));
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
        $this->validateReportFilters($request);
        $openingBalance = 0.0;
        if ($request->filled('from')) {
            $opening = $this->postedLines($request, true)->where('l.account_id', $account->id)->whereDate('j.entry_date', '<', $request->input('from'))
                ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')->first();
            $openingBalance = in_array($account->normal_balance, ['credit', 'liability', 'equity', 'revenue'])
                ? (float) $opening->credit - (float) $opening->debit
                : (float) $opening->debit - (float) $opening->credit;
        }
        $rows=$this->postedLines($request)->where('l.account_id',$account->id)->select('j.id','j.entry_date','j.entry_number','j.description_ar','j.description_en','l.debit','l.credit','l.cost_center_id','j.source_type','j.source_id')->orderBy('j.entry_date')->orderBy('j.id')->get(); $balance=$openingBalance; $rows=$rows->map(function($r)use(&$balance,$account){$balance += in_array($account->normal_balance,['credit','liability','equity','revenue']) ? ((float)$r->credit-(float)$r->debit) : ((float)$r->debit-(float)$r->credit); $r->balance=round($balance,2); return $r;}); return response()->json(['data'=>['account'=>$account,'rows'=>$rows,'opening_balance'=>round($openingBalance,2),'balance'=>round($balance,2)]]);
    }
    private function validateReportFilters(Request $request): void
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
        ]);
    }

    /** Return visible accounts with debit/credit rolled up through every parent. */
    private function accountReportRows(Request $request, ?array $types = null): array
    {
        $accounts = Account::query()->where('is_active', true)->orderBy('code')->get()->keyBy('id');
        $included = collect();
        if ($types === null) {
            $included = $accounts;
        } else {
            foreach ($accounts->whereIn('account_type', $types) as $account) {
                $cursor = $account;
                while ($cursor && !$included->has($cursor->id)) {
                    $included->put($cursor->id, $cursor);
                    $cursor = $cursor->parent_id ? $accounts->get($cursor->parent_id) : null;
                }
            }
        }
        $includedIds = $included->keys()->all();
        $direct = $this->postedLines($request)->whereIn('l.account_id', $includedIds)
            ->select('l.account_id', DB::raw('SUM(l.debit) as debit'), DB::raw('SUM(l.credit) as credit'))
            ->groupBy('l.account_id')->get()->keyBy('account_id');
        $children = [];
        foreach ($included as $account) {
            if ($account->parent_id && $included->has($account->parent_id)) $children[$account->parent_id][] = $account->id;
        }
        $rows = [];
        $rollup = function (int $id, int $level = 0) use (&$rollup, &$rows, $accounts, $included, $direct, $children): array {
            $account = $accounts->get($id);
            if (!$account || !$included->has($id)) return [0.0, 0.0];
            $line = $direct->get($id);
            $debit = (float) ($line->debit ?? 0);
            $credit = (float) ($line->credit ?? 0);
            foreach ($children[$id] ?? [] as $childId) {
                [$childDebit, $childCredit] = $rollup($childId, $level + 1);
                $debit += $childDebit;
                $credit += $childCredit;
            }
            $row = $account->toArray();
            $row['level'] = $level;
            $row['is_rollup'] = !empty($children[$id]);
            $row['period_debit'] = round($debit, 2);
            $row['period_credit'] = round($credit, 2);
            $row['debit_balance'] = round(max(0, $debit - $credit), 2);
            $row['credit_balance'] = round(max(0, $credit - $debit), 2);
            $rows[] = $row;
            return [$debit, $credit];
        };
        $roots = $included->filter(fn($account) => !$account->parent_id || !$included->has($account->parent_id));
        foreach ($roots as $root) $rollup((int) $root->id);
        usort($rows, fn($a, $b) => strcmp((string) $a['code'], (string) $b['code']));
        return [$rows, (float) $direct->sum('debit'), (float) $direct->sum('credit')];
    }

    public function trialBalance(Request $request)
    {
        $this->validateReportFilters($request);
        [$accounts, $debit, $credit] = $this->accountReportRows($request);
        return response()->json(['data' => [
            'accounts' => $accounts,
            'totals' => ['debit' => round($debit, 2), 'credit' => round($credit, 2), 'difference' => round($debit - $credit, 2)],
            'filters' => $request->only(['from', 'to', 'branch_id', 'cost_center_id']),
        ]]);
    }

    public function incomeStatement(Request $request)
    {
        $this->validateReportFilters($request);
        [$lines, $debit, $credit] = $this->accountReportRows($request, ['revenue', 'expense', 'income']);
        $revenue = 0.0; $expenses = 0.0;
        foreach ($lines as $line) {
            if ($line['account_type'] === 'revenue' || $line['account_type'] === 'income') $revenue += $line['period_credit'] - $line['period_debit'];
            if ($line['account_type'] === 'expense') $expenses += $line['period_debit'] - $line['period_credit'];
        }
        // Parent roll-ups are display-only; calculate statement totals once from direct ledger activity.
        $direct = $this->postedLines($request)->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.account_type', ['revenue', 'income', 'expense'])
            ->select('a.account_type', DB::raw('SUM(l.debit) as debit'), DB::raw('SUM(l.credit) as credit'))->groupBy('a.account_type')->get();
        $revenue = (float) $direct->whereIn('account_type', ['revenue', 'income'])->sum(fn($r) => (float) $r->credit - (float) $r->debit);
        $expenses = (float) $direct->where('account_type', 'expense')->sum(fn($r) => (float) $r->debit - (float) $r->credit);
        return response()->json(['data' => ['lines' => $lines, 'revenue' => round($revenue, 2), 'expenses' => round($expenses, 2), 'net_profit' => round($revenue - $expenses, 2), 'filters' => $request->only(['from', 'to', 'branch_id', 'cost_center_id'])]]);
    }

    public function balanceSheet(Request $request)
    {
        $this->validateReportFilters($request);
        [$lines] = $this->accountReportRows($request, ['asset', 'liability', 'equity', 'treasury']);
        $direct = $this->postedLines($request)->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.account_type', ['asset', 'liability', 'equity', 'treasury'])
            ->select('a.account_type', DB::raw('SUM(l.debit) as debit'), DB::raw('SUM(l.credit) as credit'))->groupBy('a.account_type')->get();
        $net = fn($types, $normal) => round((float) $direct->whereIn('account_type', (array) $types)->sum(fn($r) => $normal === 'debit' ? (float) $r->debit - (float) $r->credit : (float) $r->credit - (float) $r->debit), 2);
        return response()->json(['data' => ['lines' => $lines, 'assets' => $net(['asset', 'treasury'], 'debit'), 'liabilities' => $net('liability', 'credit'), 'equity' => $net('equity', 'credit'), 'filters' => $request->only(['from', 'to', 'branch_id', 'cost_center_id'])]]);
    }

    public function cashFlow(Request $request)
    {
        $this->validateReportFilters($request);
        $treasuryIds = Treasury::query()->whereNotNull('account_id')->pluck('account_id');
        $rows = $this->postedLines($request)->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where(function ($query) use ($treasuryIds) { $query->where('a.account_type', 'treasury')->orWhereIn('a.id', $treasuryIds); })
            ->select(DB::raw('DATE(j.entry_date) as date'), DB::raw('SUM(l.debit) as inflow'), DB::raw('SUM(l.credit) as outflow'))
            ->groupBy(DB::raw('DATE(j.entry_date)'))->orderBy('date')->get();
        $inflows = (float) $rows->sum('inflow'); $outflows = (float) $rows->sum('outflow');
        return response()->json(['data' => ['rows' => $rows, 'inflows' => round($inflows, 2), 'outflows' => round($outflows, 2), 'net' => round($inflows - $outflows, 2), 'filters' => $request->only(['from', 'to', 'branch_id', 'cost_center_id'])]]);
    }

    public function reverse(Request $request, JournalEntry $journalEntry) {
        $data=$request->validate(['reason'=>'required|string']); if(!$journalEntry->isPosted()) throw ValidationException::withMessages(['journal'=>'لا يمكن عكس قيد غير مرحل.']); if($journalEntry->reversals()->exists()) throw ValidationException::withMessages(['journal'=>'تم عكس هذا القيد من قبل.']);
        $reversal=DB::transaction(function()use($journalEntry,$data){$journalEntry->load('lines');$reversal=JournalEntry::create(['entry_date'=>now()->toDateString(),'description_ar'=>'عكس القيد '.$journalEntry->entry_number,'description_en'=>'Reverse entry '.$journalEntry->entry_number,'notes'=>$data['reason'],'status'=>'posted','treasury_id'=>$journalEntry->treasury_id,'source_type'=>JournalEntry::class,'source_id'=>$journalEntry->id,'reversal_of_id'=>$journalEntry->id,'posted_by'=>auth()->id(),'posted_at'=>now(),'fiscal_period_id'=>$this->periodFor(now()->toDateString())?->id]); foreach($journalEntry->lines as $line)$reversal->lines()->create(['account_id'=>$line->account_id,'debit'=>$line->credit,'credit'=>$line->debit,'description'=>'Reversal: '.$line->description,'cost_center_id'=>$line->cost_center_id,'branch_id'=>$line->branch_id]); if($journalEntry->treasury_id){$treasury=Treasury::query()->lockForUpdate()->find($journalEntry->treasury_id);$account=Account::where('code','treasury_'.$journalEntry->treasury_id)->orWhere('account_type','treasury')->first();$line=$account?$reversal->lines()->where('account_id',$account->id)->first():null;if($treasury&&$line){$amount=(float)($line->debit?:$line->credit);if($line->debit)$treasury->increment('balance',$amount);else $treasury->decrement('balance',$amount);TreasuryTransaction::create(['treasury_id'=>$treasury->id,'reference_type'=>JournalEntry::class,'reference_id'=>$reversal->id,'type'=>$line->debit?'in':'out','amount'=>$amount,'description'=>'عكس القيد '.$journalEntry->entry_number]);}}$journalEntry->update(['status'=>'cancelled']);return $reversal->load('lines');}); return response()->json(['data'=>$reversal]);
    }
}
