<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\WorkflowTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SubledgerPostingService
{
    public function __construct(private readonly AccountLedgerService $ledger)
    {
    }

    public function customerAccount(Customer $customer): Account
    {
        return $this->partyAccount($customer, 'customer', 'asset', '1100', 'حسابات العملاء', 'Accounts Receivable');
    }

    public function supplierAccount(Supplier $supplier): Account
    {
        return $this->partyAccount($supplier, 'supplier', 'liability', '2100', 'حسابات الموردين', 'Accounts Payable');
    }

    public function postCogs(SalesInvoice $invoice): ?JournalEntry
    {
        $eventKey = 'financial-cogs:salesinvoice:' . $invoice->id;
        $existing = WorkflowTransaction::where('event_key', $eventKey)->whereNotNull('journal_entry_id')->first();
        if ($existing) return $existing->journalEntry;

        $inventory = $this->detailAccount('asset', '1200-INVENTORY', 'مخزون البضائع', 'Merchandise inventory', '1200', 'المخزون', 'Inventory');
        $cogs = $this->detailAccount('expense', '5000-COGS', 'تكلفة البضاعة المباعة', 'Cost of goods sold', '5000', 'تكلفة المبيعات', 'Cost of sales');
        $amount = 0.0;

        foreach ($invoice->items as $item) {
            $product = $item->product;
            $unitCost = (float) ($product?->cost ?? 0);
            if ($unitCost <= 0 && $invoice->warehouse_id) {
                $unitCost = (float) DB::table('product_warehouse')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $invoice->warehouse_id)
                    ->value('cost');
            }
            $amount += $unitCost * (float) $item->quantity;
        }

        $amount = round($amount, 2);
        if ($amount <= 0) return null;

        return DB::transaction(function () use ($invoice, $inventory, $cogs, $amount, $eventKey) {
            $journal = JournalEntry::create([
                'entry_date' => $invoice->invoice_date ?? now()->toDateString(),
                'description_ar' => 'تكلفة البضاعة المباعة للفاتورة #' . $invoice->invoice_number,
                'description_en' => 'COGS for sales invoice #' . $invoice->invoice_number,
                'status' => 'posted',
                'source_type' => SalesInvoice::class,
                'source_id' => $invoice->id,
                'posted_by' => auth()->id(),
                'posted_at' => now(),
            ]);

            $journal->lines()->createMany([
                ['account_id' => $cogs->id, 'debit' => $amount, 'credit' => 0, 'description' => 'تكلفة البضاعة المباعة'],
                ['account_id' => $inventory->id, 'debit' => 0, 'credit' => $amount, 'description' => 'خفض قيمة المخزون'],
            ]);

            $this->ledger->updateTotals($cogs, $amount, 0);
            $this->ledger->updateTotals($inventory, 0, $amount);
            $invoice->update(['cogs_journal_entry_id' => $journal->id]);
            WorkflowTransaction::capture($eventKey, $invoice, 'cogs_posted', ['amount' => $amount], $journal->id, null, 'completed');
            return $journal;
        });
    }

    public function partyAccount(Model $party, string $kind, string $type, string $parentCode, string $parentAr, string $parentEn): Account
    {
        return DB::transaction(function () use ($party, $kind, $type, $parentCode, $parentAr, $parentEn) {
            if ($party->account_id) return Account::findOrFail($party->account_id);

            $parent = $this->controlAccount($type, $parentCode, $parentAr, $parentEn);
            $code = $parentCode . '-' . str_pad((string) $party->id, 6, '0', STR_PAD_LEFT);
            $name = $party->name ?: ucfirst($kind) . ' ' . $party->id;
            $account = Account::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'name_ar' => $name,
                    'account_type' => $type,
                    'parent_id' => $parent->id,
                    'normal_balance' => $type === 'liability' ? 'credit' : 'debit',
                    'is_header' => false,
                    'is_active' => true,
                    'debit' => 0,
                    'credit' => 0,
                    'balance' => 0,
                ]
            );
            $party->forceFill(['account_id' => $account->id])->save();
            return $account;
        });
    }

    public function controlAccount(string $type, string $code, string $ar, string $en): Account
    {
        $account = $this->ledger->defaultAccount($type, $code, $ar, $en);
        if (!$account->is_header) $account->update(['is_header' => true]);
        return $account;
    }

    public function detailAccount(string $type, string $code, string $ar, string $en, string $parentCode, string $parentAr, string $parentEn): Account
    {
        $parent = $this->controlAccount($type, $parentCode, $parentAr, $parentEn);
        return $this->ledger->defaultAccount($type, $code, $ar, $en, $parent->id);
    }
}
