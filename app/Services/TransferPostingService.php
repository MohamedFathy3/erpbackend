<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bank;
use App\Models\JournalEntry;
use App\Models\Transfer;
use App\Models\Treasury;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferPostingService
{
    public function __construct(private readonly AccountLedgerService $ledger)
    {
    }

    public function post(Transfer $transfer): JournalEntry
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->journal_entry_id) {
                return JournalEntry::with('lines')->findOrFail($transfer->journal_entry_id);
            }

            [$debit, $credit] = $this->accounts($transfer);
            if (!$debit || !$credit || $debit->id === $credit->id) {
                throw ValidationException::withMessages(['accounting' => 'تعذر تحديد حسابي المصدر والوجهة للتحويل.']);
            }

            $amount = round((float) $transfer->amount, 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'يجب أن يكون مبلغ التحويل أكبر من صفر.']);
            }

            $journal = JournalEntry::create([
                'entry_date' => $transfer->created_at?->toDateString() ?? now()->toDateString(),
                'description_ar' => 'ترحيل تحويل خزينة/بنك #' . $transfer->id,
                'description_en' => 'Treasury/bank transfer #' . $transfer->id,
                'status' => 'posted',
                'treasury_id' => $transfer->from_treasury_id ?: $transfer->to_treasury_id,
                'source_type' => Transfer::class,
                'source_id' => $transfer->id,
                'posted_by' => auth()->id(),
                'posted_at' => now(),
                'branch_id' => $transfer->fromTreasury?->branch_id ?? $transfer->toTreasury?->branch_id ?? $transfer->fromBank?->branch_id ?? $transfer->toBank?->branch_id,
            ]);
            $journal->lines()->createMany([
                ['account_id' => $debit->id, 'debit' => $amount, 'credit' => 0, 'description' => 'الطرف المدين للتحويل'],
                ['account_id' => $credit->id, 'debit' => 0, 'credit' => $amount, 'description' => 'الطرف الدائن للتحويل'],
            ]);
            $this->ledger->updateTotals($debit, $amount, 0);
            $this->ledger->updateTotals($credit, 0, $amount);
            $transfer->update(['journal_entry_id' => $journal->id]);

            return $journal->load('lines');
        });
    }

    private function accounts(Transfer $transfer): array
    {
        $source = $transfer->from_treasury_id
            ? $this->ledger->treasuryAccount(Treasury::query()->findOrFail($transfer->from_treasury_id))
            : ($transfer->from_bank_id ? $this->ledger->bankAccount(Bank::query()->findOrFail($transfer->from_bank_id)) : null);
        $destination = $transfer->to_treasury_id
            ? $this->ledger->treasuryAccount(Treasury::query()->findOrFail($transfer->to_treasury_id))
            : ($transfer->to_bank_id ? $this->ledger->bankAccount(Bank::query()->findOrFail($transfer->to_bank_id)) : null);

        if (in_array($transfer->type, ['treasury_to_treasury', 'treasury_to_bank', 'bank_to_treasury', 'bank_to_bank'], true)) {
            return [$destination, $source];
        }
        if (in_array($transfer->type, ['treasury_deposit', 'bank_deposit'], true)) {
            $cash = $destination ?: $source;
            return [$cash, app(SubledgerPostingService::class)->detailAccount('asset', '1990-CASH-CLEARING', 'حساب وسيط لحركة النقدية', 'Cash movement clearing', '1000', 'الأصول المتداولة', 'Current assets')];
        }
        if (in_array($transfer->type, ['treasury_withdraw', 'bank_withdraw'], true)) {
            return [app(SubledgerPostingService::class)->detailAccount('asset', '1990-CASH-CLEARING', 'حساب وسيط لحركة النقدية', 'Cash movement clearing', '1000', 'الأصول المتداولة', 'Current assets'), $source];
        }

        return [null, null];
    }
}
