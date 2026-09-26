<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeBonus;
use App\Models\Finance;
use App\Models\EmployeeAdvancePayment;
use App\Models\EmployeeFinancialTransaction;
use App\Models\EmployeePayroll;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Services\EmployeeAccountingPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeFinancialReportsController extends Controller
{
    private function actor(Request $request): string
    {
        return (string) ($request->user()?->name ?? $request->user()?->email ?? 'System');
    }

    private function dates(Request $request): array
    {
        return [
            $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : null,
            $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : null,
        ];
    }

    public function index(Request $request)
    {
        [$from, $to] = $this->dates($request);
        $employeeId = $request->input('employee_id');
        $employees = Employee::query()->select('id', 'name', 'name_ar', 'employee_code', 'salary')->orderBy('name')->get();
        $treasuries = Treasury::query()->select('id', 'name', 'balance', 'currency')->orderBy('name')->get();
        $bonuses = EmployeeBonus::with(['employee', 'treasury', 'finance', 'journalEntry'])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($from, fn ($q) => $q->whereDate('bonus_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('bonus_date', '<=', $to->toDateString()))
            ->latest('bonus_date')->get();
        $payrolls = EmployeePayroll::with(['employee', 'treasury', 'finance', 'journalEntry', 'paymentJournalEntry'])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($from, fn ($q) => $q->where('period_end', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('period_start', '<=', $to->toDateString()))
            ->latest('period_end')->get();
        $advances = EmployeeAdvance::with(['employee', 'treasury', 'journalEntry', 'payments.treasury', 'payments.journalEntry'])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($from, fn ($q) => $q->where('advance_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('advance_date', '<=', $to->toDateString()))
            ->latest('advance_date')->get();

        return response()->json(['status' => true, 'data' => [
            'employees' => $employees,
            'treasuries' => $treasuries,
            'payrolls' => $payrolls,
            'bonuses' => $bonuses,
            'advances' => $advances->map(fn ($advance) => array_merge($advance->toArray(), ['remaining_amount' => $advance->remaining_amount])),
            'summary' => [
                'total_bonuses' => (float) $bonuses->sum('amount'),
                'paid_bonuses' => (float) $bonuses->where('status', 'paid')->sum('amount'),
                'due_bonuses' => $bonuses->where('status', 'due')->count(),
                'total_payrolls' => (float) $payrolls->sum('net_salary'),
                'total_advances' => (float) $advances->sum('amount'),
                'total_advance_paid' => (float) $advances->sum('paid_amount'),
                'remaining_advances' => (float) $advances->sum(fn ($advance) => $advance->remaining_amount),
                'due_payrolls' => $payrolls->where('status', 'due')->count(),
                'paid_payrolls' => $payrolls->where('status', 'paid')->count(),
                'overdue_payrolls' => $payrolls->where('status', 'overdue')->count(),
            ],
        ]]);
    }

    public function statement(Request $request, Employee $employee)
    {
        [$from, $to] = $this->dates($request);
        $payrolls = EmployeePayroll::where('employee_id', $employee->id)->with(['journalEntry', 'paymentJournalEntry'])
            ->when($from, fn ($q) => $q->where('period_end', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('period_start', '<=', $to->toDateString()))->get();
        $bonuses = EmployeeBonus::where('employee_id', $employee->id)->with('journalEntry')->get();
        $advances = EmployeeAdvance::where('employee_id', $employee->id)->with(['payments.journalEntry'])
            ->when($from, fn ($q) => $q->where('advance_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('advance_date', '<=', $to->toDateString()))->get();
        $transactions = EmployeeFinancialTransaction::where('employee_id', $employee->id)
            ->when($from, fn ($q) => $q->where('transaction_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('transaction_date', '<=', $to->toDateString()))->get();
        $rows = [];
        foreach ($payrolls as $payroll) {
            $rows[] = ['date' => optional($payroll->paid_at ?? $payroll->period_end)->toDateString(), 'type' => 'salary', 'reason' => 'Salary ' . $payroll->period_start . ' - ' . $payroll->period_end, 'amount' => (float) $payroll->net_salary, 'direction' => 'credit', 'notes' => $payroll->notes, 'reference_id' => $payroll->id, 'journal_entry_id' => $payroll->payment_journal_entry_id];
        }
        foreach ($bonuses as $bonus) $rows[] = ['date' => $bonus->paid_at?->toDateString() ?? $bonus->bonus_date?->toDateString(), 'type' => 'bonus', 'reason' => 'بونص: ' . $bonus->reason, 'amount' => (float) $bonus->amount, 'direction' => 'credit', 'notes' => $bonus->notes, 'reference_id' => $bonus->id, 'journal_entry_id' => $bonus->journal_entry_id];
        foreach ($advances as $advance) {
            $rows[] = ['date' => $advance->advance_date?->toDateString(), 'type' => 'advance', 'reason' => $advance->reason, 'amount' => (float) $advance->amount, 'direction' => 'debit', 'notes' => $advance->notes, 'reference_id' => $advance->id, 'journal_entry_id' => $advance->journal_entry_id];
            foreach ($advance->payments as $payment) $rows[] = ['date' => $payment->payment_date?->toDateString(), 'type' => 'advance_payment', 'reason' => 'Advance repayment: ' . $advance->reason, 'amount' => (float) $payment->amount, 'direction' => 'credit', 'notes' => $payment->notes, 'reference_id' => $payment->id, 'journal_entry_id' => $payment->journal_entry_id];
        }
        foreach ($transactions as $transaction) $rows[] = ['date' => $transaction->transaction_date?->toDateString(), 'type' => $transaction->type, 'reason' => $transaction->reason, 'amount' => (float) $transaction->amount, 'direction' => in_array($transaction->type, ['deduction', 'other_debit'], true) ? 'debit' : 'credit', 'notes' => $transaction->notes, 'reference_id' => $transaction->id, 'journal_entry_id' => $transaction->journal_entry_id];
        usort($rows, fn ($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
        $balance = 0;
        foreach ($rows as &$row) {
            $balance += $row['direction'] === 'credit' ? $row['amount'] : -$row['amount'];
            $row['balance_after'] = $balance;
        }
        unset($row);

        return response()->json(['status' => true, 'data' => [
            'employee' => $employee->only(['id', 'name', 'name_ar', 'employee_code', 'salary']),
            'summary' => ['salary_total' => (float) $payrolls->sum('net_salary'), 'advances_total' => (float) $advances->sum('amount'), 'advances_remaining' => (float) $advances->sum(fn ($advance) => $advance->remaining_amount), 'balance' => $balance],
            'transactions' => $rows,
        ]]);
    }

    public function storeBonus(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['employee_id' => 'required|exists:employees,id', 'bonus_date' => 'required|date', 'amount' => 'required|numeric|gt:0', 'reason' => 'required|string|max:255', 'notes' => 'nullable|string']);
        $data['recorded_by_name'] = $this->actor($request);
        $bonus = EmployeeBonus::create($data);
        return response()->json(['status' => true, 'data' => $bonus->load('employee')], 201);
    }
    public function collectBonus(Request $request, EmployeeBonus $bonus): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['treasury_id' => 'required|exists:treasuries,id', 'paid_at' => 'required|date', 'notes' => 'nullable|string']);
        $result = DB::transaction(function () use ($data, $bonus, $request) {
            $bonus = EmployeeBonus::query()->lockForUpdate()->findOrFail($bonus->id);
            if ($bonus->status === 'paid' || $bonus->finance_id) throw ValidationException::withMessages(['bonus' => 'تم تحصيل هذا البونص بالفعل.']);
            $treasury = Treasury::query()->lockForUpdate()->findOrFail($data['treasury_id']);
            $amount = round((float) $bonus->amount, 2);
            if ((float) $treasury->balance < $amount) throw ValidationException::withMessages(['treasury_id' => 'رصيد الخزينة غير كافٍ لصرف البونص.']);
            $finance = Finance::create(['category' => 'bonus', 'amount' => $amount, 'description' => 'بونص الموظف: ' . ($bonus->employee?->name ?? $bonus->employee_id) . ' - ' . $bonus->reason, 'date' => $data['paid_at'], 'payment_method' => 'cash', 'treasury_id' => $treasury->id, 'branch_id' => $bonus->employee?->branch_id]);
            $treasury->decrement('balance', $amount);
            TreasuryTransaction::create(['treasury_id' => $treasury->id, 'reference_type' => EmployeeBonus::class, 'reference_id' => $bonus->id, 'type' => 'out', 'amount' => $amount, 'description' => 'صرف بونص الموظف #' . $bonus->employee_id]);
            $journal = app(\App\Services\AccountingAutoPostingService::class)->postFinance($finance);
            $bonus->update(['status' => 'paid', 'treasury_id' => $treasury->id, 'finance_id' => $finance->id, 'journal_entry_id' => $journal->id, 'paid_at' => $data['paid_at'], 'notes' => $data['notes'] ?? $bonus->notes, 'recorded_by_name' => $this->actor($request)]);
            return $bonus->fresh(['employee', 'treasury', 'finance', 'journalEntry']);
        });
        return response()->json(['status' => true, 'message' => 'تم تحصيل البونص وتسجيله في المصروفات والقيد المحاسبي.', 'data' => $result]);
    }
    public function storePayroll(Request $request, EmployeeAccountingPostingService $posting)
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id', 'treasury_id' => 'nullable|exists:treasuries,id',
            'period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start',
            'base_salary' => 'required|numeric|min:0', 'allowances' => 'nullable|numeric|min:0', 'deductions' => 'nullable|numeric|min:0',
            'advance_deductions' => 'nullable|numeric|min:0', 'due_date' => 'nullable|date', 'status' => 'nullable|in:due,paid,overdue',
            'paid_at' => 'nullable|date', 'adjustments' => 'nullable|array', 'notes' => 'nullable|string',
        ]);
        $this->calculatePayroll($data);
        if (($data['status'] ?? 'due') === 'paid' && empty($data['treasury_id'])) throw ValidationException::withMessages(['treasury_id' => 'اختيار الخزينة مطلوب عند صرف الراتب.']);

        $payroll = DB::transaction(function () use ($data, $posting) {
            $payroll = EmployeePayroll::create($data);
            $posting->postPayrollAccrual($payroll);
            $this->applyPayrollAdvanceDeductions($payroll);
            if ($payroll->status === 'paid') $posting->postPayrollPayment($payroll);
            return $payroll->fresh(['employee', 'treasury', 'journalEntry', 'paymentJournalEntry']);
        });
        return response()->json(['status' => true, 'data' => $payroll], 201);
    }

    public function updatePayroll(Request $request, EmployeePayroll $payroll, EmployeeAccountingPostingService $posting)
    {
        $data = $request->validate([
            'treasury_id' => 'nullable|exists:treasuries,id', 'base_salary' => 'sometimes|numeric|min:0',
            'allowances' => 'nullable|numeric|min:0', 'deductions' => 'nullable|numeric|min:0', 'advance_deductions' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date', 'status' => 'nullable|in:due,paid,overdue', 'paid_at' => 'nullable|date',
            'adjustments' => 'nullable|array', 'notes' => 'nullable|string',
        ]);
        if ($payroll->journal_entry_id && collect(['base_salary', 'allowances', 'deductions', 'advance_deductions'])->contains(fn ($key) => array_key_exists($key, $data) && (float) $data[$key] !== (float) $payroll->{$key})) {
            throw ValidationException::withMessages(['payroll' => 'تم ترحيل استحقاق الراتب؛ لا يمكن تغيير مبالغه بعد الترحيل. سجّل تسوية محاسبية بدلًا من ذلك.']);
        }
        if ($payroll->payment_journal_entry_id && (
            (array_key_exists('status', $data) && $data['status'] !== 'paid')
            || (array_key_exists('treasury_id', $data) && (int) $data['treasury_id'] !== (int) $payroll->treasury_id)
        )) {
            throw ValidationException::withMessages(['payroll' => 'لا يمكن تغيير حالة أو خزينة راتب تم صرفه بالفعل.']);
        }
        $merged = array_merge($payroll->toArray(), $data);
        $this->calculatePayroll($merged);
        $data['net_salary'] = $merged['net_salary'];
        if (($data['status'] ?? $payroll->status) === 'paid' && empty($data['treasury_id']) && !$payroll->treasury_id) throw ValidationException::withMessages(['treasury_id' => 'اختيار الخزينة مطلوب عند صرف الراتب.']);

        DB::transaction(function () use ($data, $payroll, $posting) {
            $payroll->update($data);
            if (!$payroll->journal_entry_id) {
                $posting->postPayrollAccrual($payroll);
                $this->applyPayrollAdvanceDeductions($payroll);
            }
            if ($payroll->status === 'paid' && !$payroll->payment_journal_entry_id) {
                if ($payroll->finance_id) throw ValidationException::withMessages(['payroll' => 'هذا الراتب مرتبط بصرف سابق؛ تمت حماية السجل من إعادة الصرف.']);
                $posting->postPayrollPayment($payroll);
            }
        });
        return response()->json(['status' => true, 'data' => $payroll->fresh(['employee', 'treasury', 'journalEntry', 'paymentJournalEntry'])]);
    }

    public function storeAdvance(Request $request, EmployeeAccountingPostingService $posting)
    {
        $data = $request->validate(['employee_id' => 'required|exists:employees,id', 'treasury_id' => 'required|exists:treasuries,id', 'amount' => 'required|numeric|gt:0', 'advance_date' => 'required|date', 'reason' => 'required|string', 'notes' => 'nullable|string']);
        $data['recorded_by_name'] = $this->actor($request);
        $advance = DB::transaction(function () use ($data, $posting) {
            $treasury = Treasury::query()->lockForUpdate()->findOrFail($data['treasury_id']);
            if ((float) $treasury->balance < (float) $data['amount']) throw ValidationException::withMessages(['treasury_id' => 'رصيد الخزينة غير كافٍ لصرف السلفة.']);
            $advance = EmployeeAdvance::create($data);
            $treasury->decrement('balance', $data['amount']);
            TreasuryTransaction::create(['treasury_id' => $treasury->id, 'reference_type' => EmployeeAdvance::class, 'reference_id' => $advance->id, 'type' => 'out', 'amount' => $data['amount'], 'description' => 'صرف سلفة الموظف #' . $advance->employee_id]);
            $posting->postAdvance($advance);
            return $advance->fresh(['employee', 'treasury', 'journalEntry']);
        });
        return response()->json(['status' => true, 'data' => $advance], 201);
    }

    public function storeAdvancePayment(Request $request, EmployeeAdvance $advance, EmployeeAccountingPostingService $posting)
    {
        $data = $request->validate(['treasury_id' => 'required|exists:treasuries,id', 'amount' => 'required|numeric|gt:0', 'payment_date' => 'required|date', 'notes' => 'nullable|string']);
        $data['recorded_by_name'] = $this->actor($request);
        $payment = DB::transaction(function () use ($data, $advance, $posting) {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $amount = (float) $data['amount'];
            if ($amount > $advance->remaining_amount) throw ValidationException::withMessages(['amount' => 'مبلغ السداد أكبر من الرصيد المتبقي من السلفة.']);
            $treasury = Treasury::query()->lockForUpdate()->findOrFail($data['treasury_id']);
            $payment = EmployeeAdvancePayment::create(array_merge($data, ['advance_id' => $advance->id]));
            $treasury->increment('balance', $amount);
            TreasuryTransaction::create(['treasury_id' => $treasury->id, 'reference_type' => EmployeeAdvancePayment::class, 'reference_id' => $payment->id, 'type' => 'in', 'amount' => $amount, 'description' => 'سداد سلفة الموظف #' . $advance->employee_id]);
            $posting->postAdvancePayment($payment);
            $newPaid = (float) $advance->paid_amount + $amount;
            $advance->update(['paid_amount' => $newPaid, 'last_payment_at' => $data['payment_date'], 'status' => $newPaid >= (float) $advance->amount ? 'paid' : 'repaying']);
            return $payment->fresh(['treasury', 'journalEntry']);
        });
        return response()->json(['status' => true, 'data' => $payment], 201);
    }

    public function storeTransaction(Request $request, EmployeeAccountingPostingService $posting)
    {
        $data = $request->validate(['employee_id' => 'required|exists:employees,id', 'transaction_date' => 'required|date', 'type' => 'required|in:allowance,deduction,other_credit,other_debit', 'reason' => 'required|string', 'amount' => 'required|numeric|gt:0', 'notes' => 'nullable|string']);
        $data['recorded_by_name'] = $this->actor($request);
        $transaction = DB::transaction(function () use ($data, $posting) {
            $transaction = EmployeeFinancialTransaction::create($data);
            $posting->postEmployeeTransaction($transaction);
            return $transaction->fresh('journalEntry');
        });
        return response()->json(['status' => true, 'data' => $transaction], 201);
    }

    private function calculatePayroll(array &$data): void
    {
        foreach (['allowances', 'deductions', 'advance_deductions'] as $key) $data[$key] = (float) ($data[$key] ?? 0);
        $data['net_salary'] = max(0, (float) ($data['base_salary'] ?? 0) + $data['allowances'] - $data['deductions'] - $data['advance_deductions']);
    }

    private function applyPayrollAdvanceDeductions(EmployeePayroll $payroll): void
    {
        $payroll->refresh();
        $target = min(
            round((float) $payroll->base_salary + (float) $payroll->allowances, 2),
            round((float) $payroll->advance_deductions, 2)
        );
        $alreadyAllocated = (float) EmployeeAdvancePayment::query()
            ->where('source_payroll_id', $payroll->id)
            ->sum('amount');
        $remainingToAllocate = round($target - $alreadyAllocated, 2);
        if ($remainingToAllocate <= 0) return;

        $advances = EmployeeAdvance::query()
            ->where('employee_id', $payroll->employee_id)
            ->whereDate('advance_date', '<=', $payroll->period_end)
            ->orderBy('advance_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($advances as $advance) {
            $outstanding = round((float) $advance->amount - (float) $advance->paid_amount, 2);
            if ($outstanding <= 0 || $remainingToAllocate <= 0) continue;

            $amount = min($outstanding, $remainingToAllocate);
            EmployeeAdvancePayment::create([
                'advance_id' => $advance->id,
                'source_payroll_id' => $payroll->id,
                'treasury_id' => null,
                'amount' => $amount,
                'payment_date' => $payroll->paid_at ?? $payroll->period_end,
                'recorded_by_name' => 'خصم سلفة من الراتب',
                'notes' => 'استرداد تلقائي من خصم راتب الفترة ' . $payroll->period_start . ' - ' . $payroll->period_end,
                'journal_entry_id' => $payroll->journal_entry_id,
            ]);

            $newPaid = min((float) $advance->amount, round((float) $advance->paid_amount + $amount, 2));
            $advance->update([
                'paid_amount' => $newPaid,
                'last_payment_at' => $payroll->paid_at ?? $payroll->period_end,
                'status' => $newPaid >= (float) $advance->amount ? 'paid' : 'repaying',
            ]);
            $remainingToAllocate = round($remainingToAllocate - $amount, 2);
        }

        if ($remainingToAllocate > 0) {
            throw ValidationException::withMessages([
                'advance_deductions' => 'قيمة خصم السلف أكبر من إجمالي السلف المستحقة على الموظف؛ تم إلغاء تسجيل الراتب.',
            ]);
        }
    }
}
