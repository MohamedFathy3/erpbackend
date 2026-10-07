<?php
namespace App\Services;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceTransferRequest;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\SalesRepresentative;
use App\Models\WorkflowTransaction;
use Illuminate\Support\Facades\DB;
class InvoiceTransferPostingService
{
    public function __construct(private readonly AccountLedgerService $ledger) {}
    public function post(InvoiceTransferRequest $transfer, float $amount): ?JournalEntry
    {
        return DB::transaction(function () use ($transfer, $amount) {
            if ($transfer->journal_entry_id) return JournalEntry::with('lines')->findOrFail($transfer->journal_entry_id);
            $this->recalculateCommission($transfer);
            if (!$transfer->from_employee_id) return null;
            $amount = round($amount, 2);
            if ($amount <= 0) throw new \InvalidArgumentException('لا يمكن إنشاء قيد تحويل لفاتورة قيمتها صفر.');
            $parent = $this->ledger->defaultAccount('asset', 'EMPLOYEE-SALES-TRANSFER', 'حسابات تحويل مبيعات الموظفين', 'Employee sales transfer accounts');
            if (!$parent->is_header) $parent->update(['is_header' => true]);
            $from = $this->employeeAccount($parent, $transfer->from_employee_id);
            $to = $this->employeeAccount($parent, $transfer->to_employee_id);
            $journal = JournalEntry::create([
                'entry_date' => now()->toDateString(),
                'description_ar' => 'تحويل ملكية فاتورة مبيعات #' . $transfer->invoice_id . ' من موظف إلى موظف',
                'description_en' => 'Sales invoice ownership transfer #' . $transfer->invoice_id,
                'status' => 'posted',
                'source_type' => InvoiceTransferRequest::class,
                'source_id' => $transfer->id,
                'posted_by' => auth()->id(),
                'posted_at' => now(),
            ]);
            $journal->lines()->createMany([
                ['account_id' => $to->id, 'debit' => $amount, 'credit' => 0, 'description' => 'مدين: الموظف المستلم للفاتورة'],
                ['account_id' => $from->id, 'debit' => 0, 'credit' => $amount, 'description' => 'دائن: الموظف المحول منه الفاتورة'],
            ]);
            $this->ledger->updateTotals($to, $amount, 0);
            $this->ledger->updateTotals($from, 0, $amount);
            $transfer->update(['journal_entry_id' => $journal->id]);
            return $journal->load('lines');
        });
    }

    private function recalculateCommission(InvoiceTransferRequest $transfer): void
    {
        $invoice = $transfer->invoice_type === 'pos'
            ? Invoice::query()->lockForUpdate()->findOrFail($transfer->invoice_id)
            : SalesInvoice::query()->lockForUpdate()->findOrFail($transfer->invoice_id);
        $targetRepresentative = SalesRepresentative::query()
            ->where('employee_id', $transfer->to_employee_id)
            ->where('active', true)
            ->firstOrFail();
        $sourceRepresentative = $transfer->from_employee_id
            ? SalesRepresentative::query()->where('employee_id', $transfer->from_employee_id)->first()
            : null;

        $rate = (float) ($targetRepresentative->commission_rate ?? 0);
        $total = $invoice instanceof Invoice
            ? (float) $invoice->net_amount
            : (float) ($invoice->net_total ?? $invoice->total_amount ?? 0);
        $oldRate = $invoice->commission_rate_snapshot !== null
            ? (float) $invoice->commission_rate_snapshot
            : (float) ($sourceRepresentative?->commission_rate ?? 0);
        $oldAmount = $invoice->commission_amount_snapshot !== null
            ? round((float) $invoice->commission_amount_snapshot, 2)
            : round($total * $oldRate / 100, 2);
        $newAmount = round($total * $rate / 100, 2);
        $delta = round($newAmount - $oldAmount, 2);

        $invoice->update([
            'commission_rate_snapshot' => $rate,
            'commission_amount_snapshot' => $newAmount,
        ]);

        $eventKey = 'invoice-transfer-commission:' . $transfer->id;
        if (abs($delta) < 0.01 || WorkflowTransaction::query()->where('event_key', $eventKey)->exists()) {
            return;
        }

        $expense = $this->ledger->defaultAccount('expense', 'SALES-COMMISSIONS', 'مصروف عمولات المبيعات', 'Sales commissions expense');
        $payable = $this->ledger->defaultAccount('liability', 'SALES-COMMISSIONS-PAYABLE', 'عمولات مبيعات مستحقة', 'Sales commissions payable');
        $amount = abs($delta);
        $journal = JournalEntry::create([
            'entry_date' => now()->toDateString(),
            'description_ar' => 'تسوية عمولة تحويل الفاتورة #' . $invoice->invoice_number,
            'description_en' => 'Commission adjustment for invoice transfer #' . $invoice->invoice_number,
            'status' => 'posted',
            'branch_id' => $invoice->branch_id,
            'source_type' => InvoiceTransferRequest::class,
            'source_id' => $transfer->id,
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);

        $debitAccount = $delta > 0 ? $expense : $payable;
        $creditAccount = $delta > 0 ? $payable : $expense;
        $journal->lines()->createMany([
            ['account_id' => $debitAccount->id, 'debit' => $amount, 'credit' => 0, 'description' => 'تسوية عمولة المندوب'],
            ['account_id' => $creditAccount->id, 'debit' => 0, 'credit' => $amount, 'description' => 'تسوية عمولة المندوب'],
        ]);
        $this->ledger->updateTotals($debitAccount, $amount, 0);
        $this->ledger->updateTotals($creditAccount, 0, $amount);
        WorkflowTransaction::capture($eventKey, $transfer, 'invoice_transfer_commission_adjusted', [
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'delta' => $delta,
        ], $journal->id, null, 'completed');
    }

    private function employeeAccount(Account $parent, ?int $employeeId): Account
    {
        $employeeId = (int) $employeeId;
        return Account::firstOrCreate(
            ['code' => 'EMPLOYEE-SALES-TRANSFER-' . $employeeId],
            ['name' => 'Employee sales transfer ' . $employeeId, 'name_ar' => 'تحويل مبيعات الموظف ' . $employeeId, 'account_type' => 'asset', 'parent_id' => $parent->id, 'normal_balance' => 'debit', 'is_header' => false, 'is_active' => true, 'debit' => 0, 'credit' => 0, 'balance' => 0]
        );
    }
}
