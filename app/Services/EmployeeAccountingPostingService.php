<?php

namespace App\Services;

use App\Models\Account;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvancePayment;
use App\Models\EmployeeFinancialTransaction;
use App\Models\EmployeePayroll;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeAccountingPostingService
{
    public function __construct(private readonly AccountLedgerService $ledger)
    {
    }

    public function postAdvance(EmployeeAdvance $advance): JournalEntry
    {
        return DB::transaction(function () use ($advance) {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            if ($advance->journal_entry_id) return JournalEntry::with('lines')->findOrFail($advance->journal_entry_id);
            $cash = $this->cash($advance->treasury_id);
            $receivable = $this->ledger->defaultAccount('asset', 'EMP-ADVANCES', 'سلف الموظفين', 'Employee advances');
            $journal = $this->entry($advance->advance_date, 'صرف سلفة موظف #' . $advance->id, 'Employee advance #' . $advance->id, EmployeeAdvance::class, $advance->id, $advance->treasury_id);
            $this->lines($journal, [[$receivable, (float)$advance->amount, 0, 'إثبات سلفة مستحقة على الموظف'], [$cash, 0, (float)$advance->amount, 'صرف السلفة من الخزينة']]);
            $advance->update(['journal_entry_id' => $journal->id]);
            return $journal->load('lines');
        });
    }

    public function postAdvancePayment(EmployeeAdvancePayment $payment): JournalEntry
    {
        return DB::transaction(function () use ($payment) {
            $payment = EmployeeAdvancePayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->journal_entry_id) return JournalEntry::with('lines')->findOrFail($payment->journal_entry_id);
            $cash = $this->cash($payment->treasury_id);
            $receivable = $this->ledger->defaultAccount('asset', 'EMP-ADVANCES', 'سلف الموظفين', 'Employee advances');
            $journal = $this->entry($payment->payment_date, 'تحصيل سداد سلفة #' . $payment->advance_id, 'Employee advance repayment #' . $payment->advance_id, EmployeeAdvancePayment::class, $payment->id, $payment->treasury_id);
            $this->lines($journal, [[$cash, (float)$payment->amount, 0, 'تحصيل سداد السلفة'], [$receivable, 0, (float)$payment->amount, 'تخفيض ذمة الموظف']]);
            $payment->update(['journal_entry_id' => $journal->id]);
            return $journal->load('lines');
        });
    }

    public function postPayrollAccrual(EmployeePayroll $payroll): JournalEntry
    {
        return DB::transaction(function () use ($payroll) {
            $payroll = EmployeePayroll::query()->lockForUpdate()->findOrFail($payroll->id);
            if ($payroll->journal_entry_id) return JournalEntry::with('lines')->findOrFail($payroll->journal_entry_id);

            $gross = round((float)$payroll->base_salary + (float)$payroll->allowances, 2);
            $advanceDeduction = min($gross, (float)$payroll->advance_deductions);
            $otherDeductions = min(max(0, $gross - $advanceDeduction), (float)$payroll->deductions);
            $net = max(0, round($gross - $advanceDeduction - $otherDeductions, 2));
            $expense = $this->ledger->defaultAccount('expense', 'SALARIES', 'مصروف الرواتب والأجور', 'Salaries and wages expense');
            $payable = $this->ledger->defaultAccount('liability', 'PAYROLL-PAYABLE', 'رواتب مستحقة الدفع', 'Payroll payable');
            $advance = $this->ledger->defaultAccount('asset', 'EMP-ADVANCES', 'سلف الموظفين', 'Employee advances');
            $deductions = $this->ledger->defaultAccount('liability', 'PAYROLL-DEDUCTIONS', 'استقطاعات رواتب مستحقة', 'Payroll deductions payable');
            $journal = $this->entry($payroll->period_end, 'استحقاق راتب موظف #' . $payroll->employee_id, 'Payroll accrual for employee #' . $payroll->employee_id, EmployeePayroll::class, $payroll->id, null);
            $lines = [[$expense, $gross, 0, 'إثبات مصروف الراتب الإجمالي']];
            if ($net > 0) $lines[] = [$payable, 0, $net, 'صافي راتب مستحق للموظف'];
            if ($advanceDeduction > 0) $lines[] = [$advance, 0, $advanceDeduction, 'تسوية جزء من سلفة الموظف'];
            if ($otherDeductions > 0) $lines[] = [$deductions, 0, $otherDeductions, 'استقطاعات راتب مستحقة'];
            $this->lines($journal, $lines);
            $payroll->update(['journal_entry_id' => $journal->id]);
            return $journal->load('lines');
        });
    }

    public function postPayrollPayment(EmployeePayroll $payroll): ?JournalEntry
    {
        return DB::transaction(function () use ($payroll) {
            $payroll = EmployeePayroll::query()->lockForUpdate()->findOrFail($payroll->id);
            if ($payroll->payment_journal_entry_id) return JournalEntry::with('lines')->findOrFail($payroll->payment_journal_entry_id);
            if (!$payroll->treasury_id) throw ValidationException::withMessages(['treasury_id' => 'اختيار الخزينة مطلوب عند صرف الراتب.']);
            $amount = round((float)$payroll->net_salary, 2);
            if ($amount <= 0) return null;
            $treasury = Treasury::query()->lockForUpdate()->findOrFail($payroll->treasury_id);
            if ((float)$treasury->balance < $amount) throw ValidationException::withMessages(['treasury_id' => 'رصيد الخزينة غير كافٍ لصرف الراتب.']);
            $cash = $this->ledger->treasuryAccount($treasury);
            $payable = $this->ledger->defaultAccount('liability', 'PAYROLL-PAYABLE', 'رواتب مستحقة الدفع', 'Payroll payable');
            $journal = $this->entry($payroll->paid_at?->toDateString() ?? now()->toDateString(), 'صرف راتب موظف #' . $payroll->employee_id, 'Payroll payment for employee #' . $payroll->employee_id, EmployeePayroll::class, $payroll->id, $treasury->id);
            $this->lines($journal, [[$payable, $amount, 0, 'تسوية الرواتب المستحقة'], [$cash, 0, $amount, 'صرف الراتب من الخزينة']]);
            $treasury->decrement('balance', $amount);
            TreasuryTransaction::create([
                'treasury_id' => $treasury->id,
                'reference_type' => EmployeePayroll::class,
                'reference_id' => $payroll->id,
                'type' => 'out',
                'amount' => $amount,
                'description' => 'صرف راتب الموظف #' . $payroll->employee_id,
            ]);
            $payroll->update(['payment_journal_entry_id' => $journal->id]);
            return $journal->load('lines');
        });
    }

    public function postEmployeeTransaction(EmployeeFinancialTransaction $transaction): JournalEntry
    {
        return DB::transaction(function () use ($transaction) {
            $transaction = EmployeeFinancialTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($transaction->journal_entry_id) return JournalEntry::with('lines')->findOrFail($transaction->journal_entry_id);
            $expense = $this->ledger->defaultAccount('expense', 'EMPLOYEE-ADJUSTMENTS', 'تسويات ومزايا الموظفين', 'Employee adjustments and benefits');
            $payable = $this->ledger->defaultAccount('liability', 'EMPLOYEE-ADJUSTMENTS-PAYABLE', 'مستحقات الموظفين', 'Employee adjustments payable');
            $amount = (float)$transaction->amount;
            $isCredit = in_array($transaction->type, ['allowance', 'other_credit'], true);
            $journal = $this->entry($transaction->transaction_date, 'تسوية موظف #' . $transaction->employee_id, 'Employee adjustment #' . $transaction->employee_id, EmployeeFinancialTransaction::class, $transaction->id, null);
            $lines = $isCredit
                ? [[$expense, $amount, 0, $transaction->reason], [$payable, 0, $amount, $transaction->reason]]
                : [[$payable, $amount, 0, $transaction->reason], [$expense, 0, $amount, $transaction->reason]];
            $this->lines($journal, $lines);
            $transaction->update(['journal_entry_id' => $journal->id]);
            return $journal->load('lines');
        });
    }

    public function postSalesCommission(SalesInvoice $invoice): ?JournalEntry
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->commission_journal_entry_id) return JournalEntry::with('lines')->findOrFail($invoice->commission_journal_entry_id);
            $rate = (float)($invoice->salesRepresentative?->commission_rate ?? 0);
            $amount = round((float)($invoice->net_total ?? $invoice->total_amount ?? 0) * $rate / 100, 2);
            if ($amount <= 0) return null;
            $expense = $this->ledger->defaultAccount('expense', 'SALES-COMMISSIONS', 'مصروف عمولات المبيعات', 'Sales commissions expense');
            $payable = $this->ledger->defaultAccount('liability', 'SALES-COMMISSIONS-PAYABLE', 'عمولات مبيعات مستحقة', 'Sales commissions payable');
            $journal = $this->entry($invoice->invoice_date, 'عمولة مندوب فاتورة #' . $invoice->invoice_number, 'Sales commission for invoice #' . $invoice->invoice_number, SalesInvoice::class, $invoice->id, null);
            $this->lines($journal, [[$expense, $amount, 0, 'إثبات عمولة المبيعات'], [$payable, 0, $amount, 'عمولة مستحقة لمندوب المبيعات']]);
            $invoice->update(['commission_journal_entry_id' => $journal->id]);
            return $journal->load('lines');
        });
    }

    private function cash(int $treasuryId): Account
    {
        $treasury = Treasury::query()->findOrFail($treasuryId);
        return $this->ledger->treasuryAccount($treasury);
    }

    private function entry($date, string $ar, string $en, string $sourceType, int $sourceId, ?int $treasuryId): JournalEntry
    {
        return JournalEntry::create([
            'entry_date' => $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : now()->toDateString(),
            'description_ar' => $ar,
            'description_en' => $en,
            'status' => 'posted',
            'treasury_id' => $treasuryId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);
    }

    private function lines(JournalEntry $journal, array $lines): void
    {
        $debits = 0.0;
        $credits = 0.0;
        foreach ($lines as [$account, $debit, $credit, $description]) {
            $debit = round((float)$debit, 2);
            $credit = round((float)$credit, 2);
            if ($debit == 0.0 && $credit == 0.0) continue;
            $journal->lines()->create(['account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'description' => $description]);
            $this->ledger->updateTotals($account, $debit, $credit);
            $debits += $debit;
            $credits += $credit;
        }
        if (round($debits, 2) !== round($credits, 2)) throw new \LogicException('القيد المحاسبي غير متوازن.');
    }
}
