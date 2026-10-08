<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\ReturnInvoice;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
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
            $extraCharge = round(max(0, (float) ($invoice->extra_charge ?? 0)), 2);
            $salesAmount = round(max(0, $amount - $extraCharge), 2);
            $receivable = $this->receivableAccount($invoice);
            $revenue = app(SubledgerPostingService::class)->detailAccount('revenue', '4000-SALES', 'إيرادات المبيعات', 'Sales revenue', '4000', 'الإيرادات', 'Revenue');
            $journal = $this->entry($invoice->created_at, 'فاتورة نقطة بيع #' . $invoice->invoice_number, 'POS invoice #' . $invoice->invoice_number, $invoice, null);
            $lines = [[$receivable, $amount, 0, 'إثبات ذمة فاتورة نقطة البيع']];
            if ($salesAmount > 0) {
                $lines[] = [$revenue, 0, $salesAmount, 'إثبات إيراد المبيعات'];
            }
            if ($extraCharge > 0) {
                $increaseRevenue = app(SubledgerPostingService::class)->detailAccount(
                    'revenue', '4100-SALES-INCREASES', 'إيرادات بند الزيادات', 'Sales increases revenue',
                    '4000', 'الإيرادات', 'Revenue'
                );
                $lines[] = [$increaseRevenue, 0, $extraCharge, 'إثبات إيراد بند الزيادات'];
            }
            $this->lines($journal, $lines);
            $invoice->update(['journal_entry_id' => $journal->id]);
            WorkflowTransaction::capture($eventKey, $invoice, 'pos_sale_posted', ['amount' => $amount, 'sales_amount' => $salesAmount, 'extra_charge' => $extraCharge], $journal->id);
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
            $rate = $invoice->commission_rate_snapshot !== null
                ? (float) $invoice->commission_rate_snapshot
                : (float) ($invoice->salesRepresentative?->commission_rate ?? 0);
            $amount = $invoice->commission_amount_snapshot !== null
                ? round((float) $invoice->commission_amount_snapshot, 2)
                : round((float) $invoice->total_amount * $rate / 100, 2);
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

    public function postReturn(ReturnInvoice $return): ?JournalEntry
    {
        return DB::transaction(function () use ($return) {
            $return = ReturnInvoice::query()->with(['invoice.customer', 'items.product'])->lockForUpdate()->findOrFail($return->id);
            $invoice = $return->invoice;
            if (!$invoice) return null;

            $eventKey = 'pos-return:' . $return->id;
            $existing = WorkflowTransaction::query()->where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
            if ($existing) {
                $return->update(['posting_journal_entry_id' => $existing->journal_entry_id]);
                return $existing->journalEntry;
            }

            $returnAmount = round((float) $return->total_amount, 2);
            $refundedAmount = min($returnAmount, round((float) $return->refunded_amount, 2));
            $receivableReduction = max(0, $returnAmount - $refundedAmount);
            $journal = null;

            if ($returnAmount > 0) {
                $salesReturns = app(SubledgerPostingService::class)->detailAccount(
                    'revenue', '4090-SALES-RETURNS', 'مردودات ومسموحات المبيعات', 'Sales returns and allowances', '4000', 'الإيرادات', 'Revenue'
                );
                $lines = [[$salesReturns, $returnAmount, 0, 'تخفيض إيراد فاتورة POS بسبب المرتجع']];

                if ($refundedAmount > 0) {
                    $method = strtolower((string) $return->refund_method);
                    $refundAccount = $method === 'cash'
                        ? ($this->ledger->cashAccount($invoice->treasury_id) ?? $this->ledger->defaultAccount('asset', 'POS-UNALLOCATED-CASH', 'نقدية POS غير مخصصة', 'Unallocated POS cash'))
                        : app(SubledgerPostingService::class)->detailAccount(
                            'asset',
                            $method === 'card' ? 'POS-CARD-CLEARING' : 'POS-WALLET-CLEARING',
                            $method === 'card' ? 'مبالغ بطاقات قيد التحصيل' : 'محافظ إلكترونية قيد التحصيل',
                            $method === 'card' ? 'Card clearing' : 'Wallet clearing',
                            '1000', 'الأصول المتداولة', 'Current assets'
                        );
                    $lines[] = [$refundAccount, 0, $refundedAmount, 'المبلغ المسترد للعميل'];
                }

                if ($receivableReduction > 0) {
                    $lines[] = [$this->receivableAccount($invoice), 0, $receivableReduction, 'تخفيض ذمة العميل بقيمة المرتجع غير المستردة نقدًا'];
                }

                $journal = $this->entry(
                    $return->created_at,
                    'مرتجع نقطة بيع #' . $return->return_number,
                    'POS return #' . $return->return_number,
                    $return,
                    $return->refund_method === 'cash' ? (int) $invoice->treasury_id : null
                );
                $this->lines($journal, $lines);
                $return->update(['posting_journal_entry_id' => $journal->id]);
                WorkflowTransaction::capture($eventKey, $return, 'pos_return_posted', [
                    'amount' => $returnAmount,
                    'refunded_amount' => $refundedAmount,
                    'receivable_reduction' => $receivableReduction,
                ], $journal->id);
            }

            $this->postReturnCogs($return);
            $this->postReturnCommission($return, $invoice);

            return $journal?->load('lines');
        });
    }

    public function makeComplimentary(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()
                ->with(['items', 'payments', 'returns', 'customer', 'salesRepresentative'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if ($invoice->is_complimentary) return $invoice;

            $returnedAmount = (float) $invoice->returns
                ->reject(fn (ReturnInvoice $return) => $return->workflow_status === 'cancelled')
                ->sum('total_amount');
            if ($returnedAmount > 0) {
                throw new \RuntimeException('لا يمكن تحويل فاتورة لها مرتجعات إلى فاتورة مجاملات.');
            }

            $cashPayments = $invoice->payments->where('method', 'cash');
            $cashRefund = round((float) $cashPayments->sum('amount'), 2);
            $treasury = null;
            if ($cashRefund > 0) {
                if (!$invoice->treasury_id) {
                    throw new \RuntimeException('لا توجد خزنة مرتبطة بالفاتورة لرد المبلغ النقدي.');
                }
                $treasury = Treasury::query()
                    ->withoutGlobalScope('branch')
                    ->lockForUpdate()
                    ->findOrFail($invoice->treasury_id);
                if ((float) $treasury->balance < $cashRefund) {
                    throw new \RuntimeException('رصيد الخزنة غير كافٍ لرد المبلغ المحصل.');
                }
            }

            if (!$invoice->journal_entry_id) $this->postSale($invoice);
            if (!$invoice->cogs_journal_entry_id) $this->postCogs($invoice);
            if (!$invoice->commission_journal_entry_id) $this->postCommission($invoice);
            foreach ($invoice->payments as $payment) {
                if (!$payment->journal_entry_id) $this->postPayment($invoice, $payment);
            }
            $invoice->refresh();
            $invoice->load(['payments', 'customer']);

            if ($invoice->journal_entry_id) {
                $saleJournal = JournalEntry::with('lines')->find($invoice->journal_entry_id);
                if ($saleJournal) $this->reversePostedJournal($invoice, $saleJournal, 'complimentary-sale', 'عكس إيراد فاتورة POS لتحويلها إلى مجاملة');
            }
            foreach ($invoice->payments as $payment) {
                if (!$payment->journal_entry_id) continue;
                $paymentJournal = JournalEntry::with('lines')->find($payment->journal_entry_id);
                if ($paymentJournal) $this->reversePostedJournal($invoice, $paymentJournal, 'complimentary-payment-' . $payment->id, 'عكس تحصيل فاتورة POS بعد تحويلها إلى مجاملة');
            }
            if ($invoice->commission_journal_entry_id) {
                $commissionJournal = JournalEntry::with('lines')->find($invoice->commission_journal_entry_id);
                if ($commissionJournal) $this->reversePostedJournal($invoice, $commissionJournal, 'complimentary-commission', 'عكس عمولة فاتورة POS بعد تحويلها إلى مجاملة');
            }

            if ($cashRefund > 0 && $treasury) {
                $treasury->decrement('balance', $cashRefund);
                TreasuryTransaction::create([
                    'treasury_id' => $treasury->id,
                    'reference_type' => Invoice::class,
                    'reference_id' => $invoice->id,
                    'type' => 'out',
                    'amount' => $cashRefund,
                    'description' => "رد مدفوعات فاتورة POS {$invoice->invoice_number} بعد تحويلها إلى مجاملة",
                    'created_by' => auth()->user() instanceof \App\Models\User ? auth()->id() : null,
                ]);
            }

            $invoice->update([
                'is_complimentary' => true,
                'discount_percentage' => 100,
                'discount_amount' => (float) $invoice->items->sum('total'),
                'paid_amount' => 0,
                'remaining_amount' => 0,
                'status' => 'paid',
            ]);
            WorkflowTransaction::capture(
                'pos-complimentary-conversion:' . $invoice->id,
                $invoice,
                'pos_invoice_converted_to_complimentary',
                ['original_total' => (float) $invoice->total_amount, 'cash_refunded' => $cashRefund],
                null,
                null,
                'completed'
            );

            return $invoice->fresh(['items', 'payments', 'returns', 'customer', 'salesRepresentative', 'treasury', 'cashier', 'branch', 'shift']);
        });
    }

    private function reversePostedJournal(Invoice $invoice, JournalEntry $original, string $eventSuffix, string $description): ?JournalEntry
    {
        $eventKey = 'pos-complimentary-reversal:' . $invoice->id . ':' . $eventSuffix;
        $existing = WorkflowTransaction::query()->where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
        if ($existing) return $existing->journalEntry;
        if ($original->status !== 'posted') return null;

        $reversal = JournalEntry::create([
            'entry_date' => now()->toDateString(),
            'description_ar' => $description . ' #' . $invoice->invoice_number,
            'description_en' => 'Reversal: ' . $invoice->invoice_number,
            'status' => 'posted',
            'treasury_id' => $original->treasury_id,
            'branch_id' => $invoice->branch_id,
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
            'reversal_of_id' => $original->id,
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);

        $lines = $original->lines->map(function ($line) {
            return [
                Account::query()->findOrFail($line->account_id),
                (float) $line->credit,
                (float) $line->debit,
                'عكس القيد الأصلي',
            ];
        })->all();
        $this->lines($reversal, $lines);
        $original->update(['status' => 'cancelled']);
        WorkflowTransaction::capture($eventKey, $invoice, 'pos_complimentary_journal_reversed', ['original_journal_id' => $original->id], $reversal->id);

        return $reversal->load('lines');
    }

    private function postReturnCogs(ReturnInvoice $return): ?JournalEntry
    {
        $eventKey = 'pos-return-cogs:' . $return->id;
        $existing = WorkflowTransaction::query()->where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
        if ($existing) return $existing->journalEntry;

        $amount = round((float) $return->items->sum(fn ($item) => (float) ($item->product?->cost ?? 0) * (float) $item->quantity), 2);
        if ($amount <= 0) return null;

        $inventory = app(SubledgerPostingService::class)->detailAccount('asset', '1200-INVENTORY', 'مخزون البضائع', 'Merchandise inventory', '1200', 'المخزون', 'Inventory');
        $cogs = app(SubledgerPostingService::class)->detailAccount('expense', '5000-COGS', 'تكلفة البضاعة المباعة', 'Cost of goods sold', '5000', 'تكلفة المبيعات', 'Cost of sales');
        $journal = $this->entry($return->created_at, 'عكس تكلفة مرتجع POS #' . $return->return_number, 'POS return COGS reversal #' . $return->return_number, $return, null);
        $this->lines($journal, [[$inventory, $amount, 0, 'إعادة تكلفة المرتجع إلى المخزون'], [$cogs, 0, $amount, 'عكس تكلفة البضاعة المباعة']]);
        WorkflowTransaction::capture($eventKey, $return, 'pos_return_cogs_posted', ['amount' => $amount], $journal->id);
        return $journal;
    }

    private function postReturnCommission(ReturnInvoice $return, Invoice $invoice): ?JournalEntry
    {
        $eventKey = 'pos-return-commission:' . $return->id;
        $existing = WorkflowTransaction::query()->where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
        if ($existing) return $existing->journalEntry;

        $rate = $invoice->commission_rate_snapshot !== null
            ? (float) $invoice->commission_rate_snapshot
            : (float) ($invoice->salesRepresentative?->commission_rate ?? 0);
        $originalCommission = $invoice->commission_amount_snapshot !== null
            ? (float) $invoice->commission_amount_snapshot
            : round((float) $invoice->total_amount * $rate / 100, 2);
        $originalTotal = (float) $invoice->total_amount;
        if ($rate <= 0 || $originalCommission <= 0 || $originalTotal <= 0) return null;

        $returnedAfter = (float) $invoice->returns()
            ->where(fn ($query) => $query->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'cancelled'))
            ->sum('total_amount');
        $returnedBefore = max(0, $returnedAfter - (float) $return->total_amount);
        $commissionBefore = $originalCommission * max(0, $originalTotal - $returnedBefore) / $originalTotal;
        $commissionAfter = $originalCommission * max(0, $originalTotal - $returnedAfter) / $originalTotal;
        $amount = round(max(0, $commissionBefore - $commissionAfter), 2);
        if ($amount <= 0) return null;

        $expense = $this->ledger->defaultAccount('expense', 'SALES-COMMISSIONS', 'مصروف عمولات المبيعات', 'Sales commissions expense');
        $payable = $this->ledger->defaultAccount('liability', 'SALES-COMMISSIONS-PAYABLE', 'عمولات مبيعات مستحقة', 'Sales commissions payable');
        $journal = $this->entry($return->created_at, 'تخفيض عمولة مرتجع POS #' . $return->return_number, 'POS return commission adjustment #' . $return->return_number, $return, null);
        $this->lines($journal, [[$payable, $amount, 0, 'تخفيض عمولة المندوب المستحقة'], [$expense, 0, $amount, 'عكس مصروف عمولة المبيعات']]);
        WorkflowTransaction::capture($eventKey, $return, 'pos_return_commission_reversed', ['amount' => $amount], $journal->id);
        return $journal;
    }

    private function receivableAccount(Invoice $invoice): Account
    {
        // A POS invoice without a customer must always use the shared POS
        // receivables account; never resolve a stale customer account link.
        if ($invoice->customer_id && $invoice->customer) {
            return app(SubledgerPostingService::class)->customerAccount($invoice->customer);
        }
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
            'branch_id' => $source instanceof Invoice
                ? $source->branch_id
                : ($source instanceof InvoicePayment
                    ? $source->invoice?->branch_id
                    : ($source instanceof ReturnInvoice ? $source->invoice?->branch_id : null)),
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
