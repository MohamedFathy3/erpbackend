<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Finance;
use App\Models\JournalEntry;
use App\Models\Revenue;
use App\Models\Treasury;
use App\Models\WorkflowTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingAutoPostingService
{
    public function __construct(private readonly AccountLedgerService $ledger)
    {
    }

    public function postFinance(Finance $finance): JournalEntry
    {
        return $this->postCashDocument($finance, 'expense', 'مصروف', 'Expense');
    }

    public function postRevenue(Revenue $revenue): JournalEntry
    {
        return $this->postCashDocument($revenue, 'revenue', 'إيراد', 'Revenue');
    }

    public function reverseDocument(Model $document, string $reason = 'تعديل أو حذف الحركة'): ?JournalEntry
    {
        return DB::transaction(function () use ($document, $reason) {
            $document = $document::query()->lockForUpdate()->findOrFail($document->getKey());
            if (!$document->journal_entry_id) return null;
            $original = JournalEntry::with('lines')->find($document->journal_entry_id);
            if (!$original || $original->status !== 'posted') return null;
            $eventKey = 'financial-document-reversal:' . strtolower(class_basename($document)) . ':' . $document->getKey() . ':' . $original->id;
            $existing = WorkflowTransaction::where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
            if ($existing) return $existing->journalEntry;

            $reversal = JournalEntry::create([
                'entry_date' => now()->toDateString(),
                'description_ar' => 'عكس ' . $reason . ' #' . $document->getKey(),
                'description_en' => 'Reversal: ' . $reason . ' #' . $document->getKey(),
                'status' => 'posted', 'source_type' => $document::class, 'source_id' => $document->getKey(),
                'posted_by' => auth()->id(), 'posted_at' => now(),
            ]);
            foreach ($original->lines as $line) {
                $debit = (float) $line->credit;
                $credit = (float) $line->debit;
                $reversal->lines()->create(['account_id' => $line->account_id, 'debit' => $debit, 'credit' => $credit, 'description' => 'عكس القيد الأصلي']);
                $this->ledger->updateTotals(Account::findOrFail($line->account_id), $debit, $credit);
            }
            $original->update(['status' => 'cancelled']);
            WorkflowTransaction::capture($eventKey, $document, 'financial_document_reversed', ['original_journal_entry_id' => $original->id], $reversal->id, null, 'completed');
            $document->update(['journal_entry_id' => null]);
            return $reversal;
        });
    }

    private function postCashDocument(Model $document, string $kind, string $labelAr, string $labelEn): JournalEntry
    {
        return DB::transaction(function () use ($document, $kind, $labelAr, $labelEn) {
            $document = $document::query()->lockForUpdate()->findOrFail($document->getKey());
            if ($document->journal_entry_id) {
                return JournalEntry::with('lines')->findOrFail($document->journal_entry_id);
            }
            $treasury = $document->treasury_id ? Treasury::query()->findOrFail($document->treasury_id) : null;
            $cash = $treasury
                ? $this->ledger->treasuryAccount($treasury)
                : $this->ledger->defaultAccount('asset', 'UNALLOCATED-CASH', 'نقدية غير مخصصة', 'Unallocated cash');
            $offset = $this->categoryAccount($kind, (string) ($document->category ?? ''), $labelAr, $labelEn);
            $amount = round((float) $document->amount, 2);
            if ($amount <= 0) throw ValidationException::withMessages(['amount' => 'قيمة الحركة يجب أن تكون أكبر من صفر.']);

            $debit = $kind === 'expense' ? $offset : $cash;
            $credit = $kind === 'expense' ? $cash : $offset;
            $sourceType = $document::class;
            $entry = JournalEntry::create([
                'entry_date' => $document->date?->toDateString() ?? now()->toDateString(),
                'entry_number' => ($kind === 'expense' ? 'EXP-' : 'REV-') . $document->id,
                'description_ar' => $labelAr . ': ' . ($document->description ?? $document->category ?? $document->id),
                'description_en' => $labelEn . ': ' . ($document->description ?? $document->category ?? $document->id),
                'status' => 'posted',
                'treasury_id' => $treasury?->id,
                'branch_id' => $document->branch_id ?? $treasury?->branch_id,
                'source_type' => $sourceType,
                'source_id' => $document->id,
                'posted_by' => auth()->id(),
                'posted_at' => now(),
            ]);
            $entry->lines()->createMany([
                ['account_id' => $debit->id, 'debit' => $amount, 'credit' => 0, 'description' => $document->description ?? $labelAr],
                ['account_id' => $credit->id, 'debit' => 0, 'credit' => $amount, 'description' => $document->description ?? $labelAr],
            ]);
            $this->ledger->updateTotals($debit, $amount, 0);
            $this->ledger->updateTotals($credit, 0, $amount);
            $document->update(['journal_entry_id' => $entry->id]);

            return $entry->load('lines');
        });
    }

    private function categoryAccount(string $kind, string $category, string $labelAr, string $labelEn): Account
    {
        $normalized = trim($category);
        $account = Account::query()->where('account_type', $kind)
            ->where(function ($query) use ($normalized, $kind) {
                $query->where('code', $normalized)
                    ->orWhere('code', $kind . '_' . $normalized)
                    ->orWhere('name', $normalized)
                    ->orWhere('name_ar', $normalized);
            })->first();
        if ($account) return $account;

        $code = $kind === 'expense' ? 'EXP-CATEGORY-' : 'REV-CATEGORY-';
        $slug = strtoupper(substr(hash('sha256', $normalized ?: $kind), 0, 10));
        $parentCode = $kind === 'expense' ? '6000' : '4000';
        $parent = app(SubledgerPostingService::class)->controlAccount($kind, $parentCode, $labelAr, $labelEn);
        return $this->ledger->defaultAccount($kind, $code . $slug, $normalized ?: $labelAr, $normalized ?: $labelEn, $parent->id);
    }
}
