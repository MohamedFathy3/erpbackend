<?php

namespace App\Services;

use App\Models\Account;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoicePayment;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceReturn;
use App\Models\SalesInvoicePayment;
use App\Models\WorkflowTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkflowPostingService
{
    public function __construct(private readonly AccountLedgerService $ledger)
    {
    }

    public function postCollection(Model $invoice, float $amount, ?int $treasuryId = null, ?int $bankId = null, ?SalesInvoicePayment $payment = null): ?JournalEntry
    {
        if ($amount <= 0) return null;
        $eventKey = $payment ? 'financial-collection:payment:' . $payment->id : 'financial-collection:' . strtolower(class_basename($invoice)) . ':' . $invoice->getKey() . ':' . number_format($amount, 2, '.', '');
        $existing = WorkflowTransaction::where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
        if ($existing) {
            if ($payment && !$payment->journal_entry_id) $payment->update(['journal_entry_id' => $existing->journal_entry_id]);
            return $existing->journalEntry;
        }
        if ($payment?->journal_entry_id) return JournalEntry::with('lines')->findOrFail($payment->journal_entry_id);

        $cash = $this->ledger->cashAccount($treasuryId, $bankId)
            ?? $this->ledger->defaultAccount('asset', 'UNALLOCATED-CASH', 'نقدية غير مخصصة', 'Unallocated cash');
        $receivable = $invoice instanceof SalesInvoice && $invoice->customer
            ? app(SubledgerPostingService::class)->customerAccount($invoice->customer)
            : app(SubledgerPostingService::class)->detailAccount('asset', '1100-OTHER', 'ذمم عملاء أخرى', 'Other accounts receivable', '1100', 'حسابات العملاء', 'Accounts receivable');
        if (!$cash || !$receivable) return null;

        return DB::transaction(function () use ($invoice, $amount, $cash, $receivable, $eventKey, $payment, $treasuryId) {
            $journal = JournalEntry::create([
                'entry_date' => $payment?->created_at?->toDateString() ?? now()->toDateString(),
                'description_ar' => 'تحصيل دفعة فاتورة مبيعات #' . $invoice->getKey(),
                'description_en' => 'Collection of sales invoice #' . $invoice->getKey(),
                'status' => 'posted', 'treasury_id' => $treasuryId, 'source_type' => $payment ? SalesInvoicePayment::class : $invoice::class,
                'source_id' => $payment?->id ?? $invoice->getKey(), 'posted_by' => auth()->id(), 'posted_at' => now(),
            ]);
            $this->postLines($journal, [[$cash, $amount, 0, 'تحصيل نقدي/بنكي'], [$receivable, 0, $amount, 'تسوية حساب العميل']]);
            $payment?->update(['journal_entry_id' => $journal->id]);
            WorkflowTransaction::capture($eventKey, $payment ?: $invoice, 'sale_payment_collected', ['amount' => $amount], $journal->id, null, 'completed');
            return $journal;
        });
    }

    public function postSale(Model $invoice): ?JournalEntry
    {
        if ($invoice instanceof SalesInvoice && $invoice->customer) app(SubledgerPostingService::class)->customerAccount($invoice->customer);
        return $this->postInvoice($invoice, 'sale', (float) ($invoice->net_total ?? $invoice->total_amount ?? 0), (int) ($invoice->treasury_id ?? 0), (string) ($invoice->payment_method ?? 'cash'), (int) ($invoice->bank_id ?? 0));
    }

    public function postPurchase(Model $invoice): ?JournalEntry
    {
        if ($invoice instanceof PurchaseInvoice && $invoice->supplier) app(SubledgerPostingService::class)->supplierAccount($invoice->supplier);
        return $this->postInvoice($invoice, 'purchase', (float) ($invoice->total_amount ?? 0), 0);
    }

    public function postPurchasePayment(Model $invoice, PurchaseInvoicePayment $payment): ?JournalEntry
    {
        $amount = (float) $payment->amount;
        if ($amount <= 0) return null;
        if ($payment->journal_entry_id) return JournalEntry::with('lines')->findOrFail($payment->journal_entry_id);
        $eventKey = 'financial-purchase-payment:' . $payment->getKey();
        $existing = WorkflowTransaction::where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
        if ($existing) {
            $payment->update(['journal_entry_id' => $existing->journal_entry_id]);
            return $existing->journalEntry;
        }
        $cash = $this->ledger->cashAccount($payment->treasury_id, $payment->bank_id);
        $payable = $invoice instanceof PurchaseInvoice && $invoice->supplier
            ? app(SubledgerPostingService::class)->supplierAccount($invoice->supplier)
            : Account::active()->where('account_type', 'liability')->orderBy('id')->first();
        if (!$cash || !$payable) return null;

        return DB::transaction(function () use ($invoice, $payment, $amount, $cash, $payable, $eventKey) {
            $journal = JournalEntry::create([
                'entry_date' => $payment->payment_date,
                'description_ar' => 'سداد مورد لفاتورة مشتريات #' . $invoice->getKey(),
                'description_en' => 'Supplier payment for purchase invoice #' . $invoice->getKey(),
                'status' => 'posted', 'treasury_id' => $payment->treasury_id, 'source_type' => PurchaseInvoicePayment::class,
                'source_id' => $payment->id, 'posted_by' => auth()->id(), 'posted_at' => now(),
            ]);
            $this->postLines($journal, [[$payable, $amount, 0, 'تخفيض رصيد المورد'], [$cash, 0, $amount, 'صرف من الخزينة لسداد المورد']]);
            $payment->update(['journal_entry_id' => $journal->id]);
            WorkflowTransaction::capture($eventKey, $payment, 'purchase_payment_posted', ['amount' => $amount], $journal->id, null, 'completed');
            return $journal;
        });
    }

    public function postReturn(Model $return, string $direction, float $amount, ?int $treasuryId = null): ?JournalEntry
    {
        $type = $direction === 'sales_return' ? 'sales_return' : 'purchase_return';
        $journal = $this->postInvoice($return, $type, $amount, (int) ($treasuryId ?? 0));
        if ($type === 'sales_return' && $return instanceof SalesInvoiceReturn) $this->postSalesReturnCogs($return);
        return $journal;
    }

    private function postSalesReturnCogs(SalesInvoiceReturn $return): ?JournalEntry
    {
        $return = SalesInvoiceReturn::query()->with('items.product')->findOrFail($return->id);
        if ($return->cogs_journal_entry_id) return JournalEntry::with('lines')->find($return->cogs_journal_entry_id);
        $eventKey = 'financial-sales-return-cogs:' . $return->id;
        $existing = WorkflowTransaction::where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
        if ($existing) {
            $return->update(['cogs_journal_entry_id' => $existing->journal_entry_id]);
            return $existing->journalEntry;
        }

        $amount = 0.0;
        foreach ($return->items as $item) {
            $unitCost = (float) ($item->product?->cost ?? 0);
            if ($unitCost <= 0 && $return->warehouse_id) {
                $unitCost = (float) DB::table('product_warehouse')->where('product_id', $item->product_id)->where('warehouse_id', $return->warehouse_id)->value('cost');
            }
            $amount += $unitCost * (float) $item->quantity;
        }
        $amount = round($amount, 2);
        if ($amount <= 0) return null;

        return DB::transaction(function () use ($return, $amount, $eventKey) {
            $inventory = app(SubledgerPostingService::class)->detailAccount('asset', '1200-INVENTORY', 'مخزون البضائع', 'Merchandise inventory', '1200', 'المخزون', 'Inventory');
            $cogs = app(SubledgerPostingService::class)->detailAccount('expense', '5000-COGS', 'تكلفة البضاعة المباعة', 'Cost of goods sold', '5000', 'تكلفة المبيعات', 'Cost of sales');
            $journal = JournalEntry::create(['entry_date' => $return->created_at?->toDateString() ?? now()->toDateString(), 'description_ar' => 'عكس تكلفة مخزون مرتجع المبيعات #' . $return->id, 'description_en' => 'Reverse COGS for sales return #' . $return->id, 'status' => 'posted', 'source_type' => SalesInvoiceReturn::class, 'source_id' => $return->id, 'posted_by' => auth()->id(), 'posted_at' => now()]);
            $this->postLines($journal, [[$inventory, $amount, 0, 'إعادة تكلفة المنتج المرتجع للمخزون'], [$cogs, 0, $amount, 'عكس تكلفة البضاعة المباعة']]);
            $return->update(['cogs_journal_entry_id' => $journal->id]);
            WorkflowTransaction::capture($eventKey, $return, 'sales_return_cogs_posted', ['amount' => $amount], $journal->id, null);
            return $journal;
        });
    }

    public function reverseInvoice(Model $source, string $type): ?JournalEntry
    {
        return DB::transaction(function () use ($source, $type) {
            $source = $source::query()->lockForUpdate()->findOrFail($source->getKey());
            $eventKey = 'financial-reversal:' . strtolower(class_basename($source)) . ':' . $source->getKey();
            $existing = WorkflowTransaction::where('event_key', $eventKey)->first();
            if ($existing?->journal_entry_id) return $existing->journalEntry;

            $reversed = null;
            if ($source->posting_journal_entry_id) {
                $original = JournalEntry::with('lines')->find($source->posting_journal_entry_id);
                if ($original) $reversed = $this->reverseJournal($original, 'عكس ' . $this->label($type), $source::class, $source->getKey());
            }

            if ($type === 'sale' && $source instanceof SalesInvoice) {
                foreach ([$source->commission_journal_entry_id, $source->cogs_journal_entry_id] as $journalId) {
                    if ($journalId && ($entry = JournalEntry::with('lines')->find($journalId)) && $entry->status === 'posted') {
                        $this->reverseJournal($entry, 'عكس قيد تكلفة/عمولة فاتورة المبيعات', SalesInvoice::class, $source->id);
                    }
                }
                foreach (SalesInvoicePayment::where('sales_invoice_id', $source->id)->whereNotNull('journal_entry_id')->get() as $payment) {
                    $entry = JournalEntry::with('lines')->find($payment->journal_entry_id);
                    if ($entry && $entry->status === 'posted') $this->reverseJournal($entry, 'عكس تحصيل فاتورة المبيعات', SalesInvoicePayment::class, $payment->id);
                }
            }
            if ($type === 'sales_return' && !empty($source->cogs_journal_entry_id)) {
                $entry = JournalEntry::with('lines')->find($source->cogs_journal_entry_id);
                if ($entry && $entry->status === 'posted') $this->reverseJournal($entry, 'عكس تكلفة مرتجع المبيعات', $source::class, $source->getKey());
            }

            $source->loadMissing('items.product');
            $warehouseId = $source->warehouse_id ?? $source->invoice?->warehouse_id ?? $source->purchaseInvoice?->warehouse_id;
            $movementIds = [];
            if ($type === 'sale' && method_exists($source, 'items')) {
                foreach ($source->items as $item) {
                    $movement = app(InventoryMovementService::class)->apply([
                        'product_id' => $item->product_id, 'product_unit_id' => $item->product_unit_id ?? null,
                        'size_id' => $item->size_id ?? null, 'color_id' => $item->color_id ?? null,
                        'branch_id' => $source->branch_id ?? null, 'warehouse_id' => $warehouseId,
                        'movement_type' => 'sale_cancelled', 'quantity_delta' => (float) $item->quantity,
                        'reference_type' => $source::class, 'reference_id' => $source->getKey(), 'notes' => 'عكس حركة مخزون فاتورة المبيعات',
                    ]);
                    $movementIds[] = $movement->id;
                }
            } elseif ($warehouseId && method_exists($source, 'items')) {
                $movementType = in_array($type, ['sale', 'purchase_return'], true) ? 'receipt' : 'issue';
                foreach ($source->items as $item) {
                    $product = Product::lockForUpdate()->find($item->product_id);
                    if (!$product) continue;
                    $quantity = (float) $item->quantity;
                    if ($movementType === 'issue' && $product->stock < $quantity) throw new \RuntimeException("لا يمكن إلغاء الفاتورة؛ مخزون المنتج {$product->name} غير كافٍ لعكس عملية الشراء");
                    $movementType === 'receipt' ? $product->increment('stock', $quantity) : $product->decrement('stock', $quantity);
                    $warehouseStock = DB::table('product_warehouse')->where('product_id', $product->id)->where('warehouse_id', $warehouseId);
                    $movementType === 'receipt' ? $warehouseStock->increment('stock', $quantity) : $warehouseStock->decrement('stock', $quantity);
                    $movement = InventoryMovement::create(['warehouse_id' => $warehouseId, 'product_id' => $product->id, 'reference_type' => $source::class, 'reference_id' => $source->getKey(), 'type' => $movementType, 'quantity' => $quantity, 'unit_cost' => (float) ($product->cost ?? $item->price ?? 0), 'total_cost' => $quantity * (float) ($product->cost ?? $item->price ?? 0), 'note' => 'عكس الفاتورة وإلغاء المعاملة']);
                    $movementIds[] = $movement->id;
                }
            }
            WorkflowTransaction::capture($eventKey, $source, 'invoice_reversed', ['type' => $type, 'inventory_movement_ids' => $movementIds], $reversed?->id, $movementIds[0] ?? null, 'completed');
            return $reversed;
        });
    }

    private function reverseJournal(JournalEntry $original, string $label, string $sourceType, int $sourceId): JournalEntry
    {
        $journal = JournalEntry::create(['entry_date' => now()->toDateString(), 'description_ar' => $label . ' #' . $sourceId, 'description_en' => 'Reversal: ' . $label . ' #' . $sourceId, 'status' => 'posted', 'source_type' => $sourceType, 'source_id' => $sourceId, 'posted_by' => auth()->id(), 'posted_at' => now()]);
        foreach ($original->lines as $line) {
            $debit = (float) $line->credit;
            $credit = (float) $line->debit;
            $journal->lines()->create(['account_id' => $line->account_id, 'debit' => $debit, 'credit' => $credit, 'description' => 'عكس القيد الأصلي']);
            $this->ledger->updateTotals(Account::findOrFail($line->account_id), $debit, $credit);
        }
        $original->update(['status' => 'cancelled']);
        return $journal;
    }

    private function postInvoice(Model $source, string $type, float $amount, int $treasuryId, ?string $paymentMethod = null, int $bankId = 0): ?JournalEntry
    {
        if ($amount <= 0) return null;
        $eventKey = 'financial-posting:' . strtolower(class_basename($source)) . ':' . $source->getKey() . ':' . $type;
        $existing = WorkflowTransaction::where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
        if ($existing) return $existing->journalEntry;

        $accounts = $this->accountsFor($type, $treasuryId, $paymentMethod, $bankId);
        if ($type === 'sale' && $source instanceof SalesInvoice && $source->customer && $paymentMethod === 'credit') $accounts['debit'] = app(SubledgerPostingService::class)->customerAccount($source->customer);
        if ($type === 'purchase') $accounts['debit'] = app(SubledgerPostingService::class)->detailAccount('asset', '1200-INVENTORY', 'مخزون البضائع', 'Merchandise inventory', '1200', 'المخزون', 'Inventory');
        if ($type === 'purchase' && $source instanceof PurchaseInvoice && $source->supplier) $accounts['credit'] = app(SubledgerPostingService::class)->supplierAccount($source->supplier);
        if ($type === 'purchase' && !$accounts['credit']) $accounts['credit'] = app(SubledgerPostingService::class)->detailAccount('liability', '2100-OTHER-PAYABLES', 'ذمم موردين أخرى', 'Other accounts payable', '2100', 'الالتزامات المتداولة', 'Current liabilities');
        if ($type === 'sales_return') {
            $accounts['debit'] = app(SubledgerPostingService::class)->detailAccount('revenue', '4090-SALES-RETURNS', 'مردودات ومسموحات المبيعات', 'Sales returns and allowances', '4000', 'الإيرادات', 'Revenue');
            if (!$this->ledger->cashAccount($treasuryId ?: null) && $source instanceof SalesInvoiceReturn && $source->invoice?->customer) {
                $accounts['credit'] = app(SubledgerPostingService::class)->customerAccount($source->invoice->customer);
            }
        }
        if ($type === 'purchase_return' && !$this->ledger->cashAccount($treasuryId ?: null)) {
            $purchaseInvoice = $source->purchaseInvoice ?? $source->invoice ?? null;
            if ($purchaseInvoice?->supplier) $accounts['debit'] = app(SubledgerPostingService::class)->supplierAccount($purchaseInvoice->supplier);
        }
        if (!$accounts['debit'] || !$accounts['credit']) {
            WorkflowTransaction::capture($eventKey, $source, $type . '_pending_finance', ['amount' => $amount, 'reason' => 'لم يتم ضبط الحسابات الافتراضية'], null, null, 'pending_finance');
            return null;
        }

        return DB::transaction(function () use ($source, $type, $amount, $accounts, $eventKey) {
            $journal = JournalEntry::create(['entry_date' => $source->invoice_date ?? now()->toDateString(), 'description_ar' => $this->label($type) . ' #' . $source->getKey(), 'description_en' => ucfirst(str_replace('_', ' ', $type)) . ' #' . $source->getKey(), 'status' => 'posted', 'source_type' => $source::class, 'source_id' => $source->getKey(), 'posted_by' => auth()->id(), 'posted_at' => now()]);
            $this->postLines($journal, [[$accounts['debit'], $amount, 0, $this->label($type) . ' - مدين'], [$accounts['credit'], 0, $amount, $this->label($type) . ' - دائن']]);
            WorkflowTransaction::capture($eventKey, $source, $type . '_posted', ['amount' => $amount], $journal->id, null);
            return $journal;
        });
    }

    private function postLines(JournalEntry $journal, array $lines): void
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
        if (round($debits, 2) !== round($credits, 2)) throw new \LogicException('القيد المحاسبي غير متوازن.');
    }

    private function accountsFor(string $type, int $treasuryId, ?string $paymentMethod = null, int $bankId = 0): array
    {
        $cash = $this->ledger->cashAccount($treasuryId ?: null, $bankId ?: null);
        $asset = app(SubledgerPostingService::class)->detailAccount('asset', '1200-INVENTORY', 'مخزون البضائع', 'Merchandise inventory', '1200', 'المخزون', 'Inventory');
        $revenue = app(SubledgerPostingService::class)->detailAccount('revenue', '4000-SALES', 'إيرادات المبيعات', 'Sales revenue', '4000', 'الإيرادات', 'Revenue');
        $expense = Account::active()->where('account_type', 'expense')->orderBy('id')->first()
            ?: $this->ledger->defaultAccount('expense', '6000', 'مصروفات أخرى', 'Other expenses');
        if ($type === 'sale') return ['debit' => $paymentMethod === 'credit' ? $asset : ($cash ?: $asset), 'credit' => $revenue];
        if ($type === 'purchase') return ['debit' => $asset, 'credit' => app(SubledgerPostingService::class)->detailAccount('liability', '2100-OTHER-PAYABLES', 'ذمم موردين أخرى', 'Other accounts payable', '2100', 'الالتزامات المتداولة', 'Current liabilities')];
        if ($type === 'sales_return') return ['debit' => app(SubledgerPostingService::class)->detailAccount('revenue', '4090-SALES-RETURNS', 'مردودات ومسموحات المبيعات', 'Sales returns and allowances', '4000', 'الإيرادات', 'Revenue'), 'credit' => $cash ?: app(SubledgerPostingService::class)->detailAccount('asset', '1100-OTHER', 'ذمم عملاء أخرى', 'Other accounts receivable', '1100', 'حسابات العملاء', 'Accounts receivable')];
        if ($type === 'purchase_return') return ['debit' => $cash ?: app(SubledgerPostingService::class)->detailAccount('liability', '2100-OTHER-PAYABLES', 'ذمم موردين أخرى', 'Other accounts payable', '2100', 'الالتزامات المتداولة', 'Current liabilities'), 'credit' => $asset];
        return ['debit' => $cash ?: $asset, 'credit' => $asset ?: $expense];
    }

    private function label(string $type): string
    {
        return ['sale' => 'فاتورة مبيعات', 'purchase' => 'فاتورة مشتريات', 'sales_return' => 'مرتجع مبيعات', 'purchase_return' => 'مرتجع مشتريات'][$type] ?? 'معاملة مالية';
    }
}
