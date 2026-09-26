<?php
namespace App\Services;
use App\Models\Account;
use App\Models\InvoiceTransferRequest;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
class InvoiceTransferPostingService
{
    public function __construct(private readonly AccountLedgerService $ledger) {}
    public function post(InvoiceTransferRequest $transfer, float $amount): JournalEntry
    {
        return DB::transaction(function () use ($transfer, $amount) {
            if ($transfer->journal_entry_id) return JournalEntry::with('lines')->findOrFail($transfer->journal_entry_id);
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
    private function employeeAccount(Account $parent, ?int $employeeId): Account
    {
        $employeeId = (int) $employeeId;
        return Account::firstOrCreate(
            ['code' => 'EMPLOYEE-SALES-TRANSFER-' . $employeeId],
            ['name' => 'Employee sales transfer ' . $employeeId, 'name_ar' => 'تحويل مبيعات الموظف ' . $employeeId, 'account_type' => 'asset', 'parent_id' => $parent->id, 'normal_balance' => 'debit', 'is_header' => false, 'is_active' => true, 'debit' => 0, 'credit' => 0, 'balance' => 0]
        );
    }
}
