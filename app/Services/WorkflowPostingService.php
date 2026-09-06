<?php
namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\WorkflowTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkflowPostingService
{
    public function postSale(Model $invoice): ?JournalEntry
    {
        return $this->postInvoice($invoice, 'sale', (float) ($invoice->net_total ?? $invoice->total_amount ?? 0), (int) ($invoice->treasury_id ?? 0));
    }

    public function postPurchase(Model $invoice): ?JournalEntry
    {
        return $this->postInvoice($invoice, 'purchase', (float) ($invoice->total_amount ?? 0), (int) ($invoice->treasury_id ?? 0));
    }

    public function postReturn(Model $return, string $direction, float $amount, ?int $treasuryId = null): ?JournalEntry
    {
        return $this->postInvoice($return, $direction === 'sales_return' ? 'sales_return' : 'purchase_return', $amount, (int) ($treasuryId ?? 0));
    }

    private function postInvoice(Model $source, string $type, float $amount, int $treasuryId): ?JournalEntry
    {
        if ($amount <= 0) return null;
        $eventKey = 'financial-posting:' . strtolower(class_basename($source)) . ':' . $source->getKey() . ':' . $type;
        if (WorkflowTransaction::where('event_key', $eventKey)->whereNotNull('journal_entry_id')->exists()) {
            return WorkflowTransaction::where('event_key', $eventKey)->first()->journalEntry;
        }

        $accounts = $this->accountsFor($type, $treasuryId);
        if (!$accounts['debit'] || !$accounts['credit']) {
            WorkflowTransaction::capture($eventKey, $source, $type . '_pending_finance', ['amount' => $amount, 'reason' => 'لم يتم ضبط الحسابات الافتراضية'], null, null, 'pending_finance');
            return null;
        }

        $journal = DB::transaction(function () use ($source, $type, $amount, $accounts) {
            $journal = JournalEntry::create(['entry_date' => now()->toDateString(), 'description_ar' => $this->label($type) . ' #' . $source->getKey(), 'description_en' => ucfirst(str_replace('_', ' ', $type)) . ' #' . $source->getKey(), 'status' => 'posted']);
            $journal->lines()->createMany([
                ['account_id' => $accounts['debit']->id, 'debit' => $amount, 'credit' => 0, 'description' => $this->label($type) . ' - مدين'],
                ['account_id' => $accounts['credit']->id, 'debit' => 0, 'credit' => $amount, 'description' => $this->label($type) . ' - دائن'],
            ]);
            Account::whereKey($accounts['debit']->id)->increment('debit', $amount);
            Account::whereKey($accounts['credit']->id)->increment('credit', $amount);
            WorkflowTransaction::capture('financial-posting:' . strtolower(class_basename($source)) . ':' . $source->getKey() . ':' . $type, $source, $type . '_posted', ['amount' => $amount], $journal->id);
            return $journal;
        });
        return $journal;
    }

    private function accountsFor(string $type, int $treasuryId): array
    {
        $treasuryAccount = $treasuryId ? Account::whereHas('treasury', fn ($q) => $q->whereKey($treasuryId))->first() : Account::treasury()->active()->first();
        $asset = Account::active()->where('account_type', 'asset')->first();
        $revenue = Account::active()->where('account_type', 'revenue')->first();
        $expense = Account::active()->where('account_type', 'expense')->first();
        return match ($type) {
            'sale' => ['debit' => $treasuryAccount ?: $asset, 'credit' => $revenue],
            'purchase' => ['debit' => $asset, 'credit' => $treasuryAccount ?: Account::active()->where('account_type', 'liability')->first()],
            'sales_return' => ['debit' => $revenue ?: $expense, 'credit' => $treasuryAccount ?: $asset],
            default => ['debit' => $treasuryAccount ?: $asset, 'credit' => $asset ?: $expense],
        };
    }

    private function label(string $type): string
    {
        return ['sale' => 'فاتورة مبيعات', 'purchase' => 'فاتورة مشتريات', 'sales_return' => 'مرتجع مبيعات', 'purchase_return' => 'مرتجع مشتريات'][$type] ?? 'معاملة مالية';
    }
}
