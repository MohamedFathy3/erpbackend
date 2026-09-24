<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\WorkflowTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PosAccountingPostingService
{
    public function __construct(private readonly AccountLedgerService $ledger)
    {
    }

    public function postSale(Invoice $invoice): ?JournalEntry
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->journal_entry_id) return JournalEntry::with('lines')->findOrFail($invoice->journal_entry_id);

            $eventKey = 'pos-sale:' . $invoice->id;
            $existing = WorkflowTransaction::query()->where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
            if ($existing) {
                $invoice->update(['journal_entry_id' => $existing->journal_entry_id]);
                return $existing->journalEntry;
            }

            $amount = round((float) $invoice->total_amount, 2);
            if ($amount <= 0) return null;
            $receivable = $this->receivableAccount($invoice);
            $revenue = app(SubledgerPostingService::class)->detailAccount('revenue', '4000-SALES', 'إيرادات المبيعات', 'Sales revenue', '4000', 'الإيرادات', 'Revenue');
            $journal = $this->entry($invoice->created_at, 'فاتورة نقطة بيع #' . $invoice->invoice_number, 'POS invoice #' . $invoice->invoice_number, $invoice, null);
            $this->lines($journal, [[$receivable, $amount, 0, 'إثبات ذمة فاتورة نقطة البيع'], [$revenue, 0, $amount, 'إثبات إيراد المبيعات']]);
            $invoice->update(['journal_entry_id' => $journal->id]);
            WorkflowTransaction::capture($eventKey, $invoice, 'pos_sale_posted', ['amount' => $amount], $journal->id);
            return $journal->load('lines');
        });
    }

    public function postPayment(Invoice $invoice, InvoicePayment $payment): ?JournalEntry
    {
        return DB::transaction(function () use ($invoice, $payment) {
            $payment = InvoicePayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->journal_entry_id) return JournalEntry::with('lines')->findOrFail($payment->journal_entry_id);
            $eventKey = 'pos-payment:' . $payment->id;
            $existing = WorkflowTransaction::query()->where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
            if ($existing) {
                $payment->update(['journal_entry_id' => $existing->journal_entry_id]);
                return $existing->journalEntry;
            }

            $amount = round((float) $payment->amount, 2);
            if ($amount <= 0) return null;
            $invoice = $invoice->fresh(['customer']);
            $payment->setRelation('invoice', $invoice);
            $receivable = $this->receivableAccount($invoice);
            $method = strtolower((string) $payment->method);
            $cash = $method === 'cash'
                ? ($this->ledger->cashAccount($invoice->treasury_id) ?? $this->ledger->defaultAccount('asset', 'POS-UNALLOCATED-CASH', 'نقدية POS غير مخصصة', 'Unallocated POS cash'))
                : app(SubledgerPostingService::class)->detailAccount(
                    'asset',
                    $method === 'card' ? 'POS-CARD-CLEARING' : 'POS-WALLET-CLEARING',
                    $method === 'card' ? 'مبالغ بطاقات قيد التحصيل' : 'محافظ إلكترونية قيد التحصيل',
                    $method === 'card' ? 'Card clearing' : 'Wallet clearing',
                    '1000',
                    'الأصول المتداولة',
                    'Current assets'
                );
            $journal = $this->entry($payment->created_at, 'تحصيل نقطة بيع #' . $invoice->invoice_number, 'POS collection #' . $invoice->invoice_number, $payment, $method === 'cash' ? (int) $invoice->treasury_id : null);
            $this->lines($journal, [[$cash, $amount, 0, 'إثبات النقدية/التحصيل'], [$receivable, 0, $amount, 'تسوية ذمة العميل']]);
            $payment->update(['journal_entry_id' => $journal->id]);
            WorkflowTransaction::capture($eventKey, $payment, 'pos_payment_posted', ['amount' => $amount, 'method' => $method], $journal->id);
            return $journal->load('lines');
        });
    }

    public function postCogs(Invoice $invoice): ?JournalEntry
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->with('items.product')->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->cogs_journal_entry_id) return JournalEntry::with('lines')->findOrFail($invoice->cogs_journal_entry_id);
            $eventKey = 'pos-cogs:' . $invoice->id;
            $existing = WorkflowTransaction::query()->where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
            if ($existing) {
                $invoice->update(['cogs_journal_entry_id' => $existing->journal_entry_id]);
                return $existing->journalEntry;
            }

            $amount = 0.0;
            foreach ($invoice->items as $item) {
                $amount += (float) ($item->product?->cost ?? 0) * (float) $item->quantity;
            }
            $amount = round($amount, 2);
            if ($amount <= 0) return null;

            $inventory = app(SubledgerPostingService::class)->detailAccount('asset', '1200-INVENTORY', 'مخزون البضائع', 'Merchandise inventory', '1200', 'المخزون', 'Inventory');
            $cogs = app(SubledgerPostingService::class)->detailAccount('expense', '5000-COGS', 'تكلفة البضاعة المباعة', 'Cost of goods sold', '5000', 'تكلفة المبيعات', 'Cost of sales');
            $journal = $this->entry($invoice->created_at, 'تكلفة مبيعات نقطة بيع #' . $invoice->invoice_number, 'POS COGS #' . $invoice->invoice_number, $invoice, null);
            $this->lines($journal, [[$cogs, $amount, 0, 'تكلفة البضاعة المباعة'], [$inventory, 0, $amount, 'تخفيض المخزون']]);
            $invoice->update(['cogs_journal_entry_id' => $journal->id]);
            WorkflowTransaction::capture($eventKey, $invoice, 'pos_cogs_posted', ['amount' => $amount], $journal->id);
            return $journal->load('lines');
        });
    }

    public function postCommission(Invoice $invoice): ?JournalEntry
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->with('salesRepresentative')->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->commission_journal_entry_id) return JournalEntry::with('lines')->findOrFail($invoice->commission_journal_entry_id);
            $rate = (float) ($invoice->salesRepresentative?->commission_rate ?? 0);
            $amount = round((float) $invoice->total_amount * $rate / 100, 2);
            if ($amount <= 0) return null;

            $eventKey = 'pos-commission:' . $invoice->id;
            $existing = WorkflowTransaction::query()->where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
            if ($existing) {
                $invoice->update(['commission_journal_entry_id' => $existing->journal_entry_id]);
                return $existing->journalEntry;
            }
            $expense = $this->ledger->defaultAccount('expense', 'SALES-COMMISSIONS', 'مصروف عمولات المبيعات', 'Sales commissions expense');
            $payable = $this->ledger->defaultAccount('liability', 'SALES-COMMISSIONS-PAYABLE', 'عمولات مبيعات مستحقة', 'Sales commissions payable');
            $journal = $this->entry($invoice->created_at, 'عمولة POS #' . $invoice->invoice_number, 'POS sales commission #' . $invoice->invoice_number, $invoice, null);
            $this->lines($journal, [[$expense, $amount, 0, 'إثبات عمولة المبيعات'], [$payable, 0, $amount, 'عمولة مستحقة للمندوب']]);
            $invoice->update(['commission_journal_entry_id' => $journal->id]);
            WorkflowTransaction::capture($eventKey, $invoice, 'pos_commission_posted', ['amount' => $amount], $journal->id);
            return $journal->load('lines');
        });
    }

    private function receivableAccount(Invoice $invoice): Account
    {
        if ($invoice->customer) return app(SubledgerPostingService::class)->customerAccount($invoice->customer);
        return app(SubledgerPostingService::class)->detailAccount('asset', '1100-POS-CUSTOMERS', 'ذمم عملاء مبيعات POS', 'POS customer receivables', '1100', 'حسابات العملاء', 'Accounts receivable');
    }

    private function entry($date, string $ar, string $en, Model $source, ?int $treasuryId): JournalEntry
    {
        return JournalEntry::create([
            'entry_date' => $date ? Carbon::parse($date)->toDateString() : now()->toDateString(),
            'description_ar' => $ar,
            'description_en' => $en,
            'status' => 'posted',
            'treasury_id' => $treasuryId,
            'branch_id' => $source instanceof Invoice ? $source->branch_id : ($source instanceof InvoicePayment ? $source->invoice?->branch_id : null),
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);
    }

    private function lines(JournalEntry $journal, array $lines): void
    {
        $debits = 0.0;
        $credits = 0.0;
        foreach ($lines as [$account, $debit, $credit, $description]) {
            $debit = round((float) $debit, 2);
            $credit = round((float) $credit, 2);
            $journal->lines()->create(['account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'description' => $description]);
            $this->ledger->updateTotals($account, $debit, $credit);
            $debits += $debit;
            $credits += $credit;
        }
        if (round($debits, 2) !== round($credits, 2)) throw new \LogicException('قيد POS غير متوازن.');
    }
}
