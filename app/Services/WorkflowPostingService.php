<?php
namespace App\Services;

use App\Models\Account;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\WorkflowTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkflowPostingService
{
    public function postCollection(Model $invoice, float $amount, ?int $treasuryId = null): ?JournalEntry
    {
        if ($amount <= 0) return null;
        $eventKey = 'financial-collection:' . strtolower(class_basename($invoice)) . ':' . $invoice->getKey() . ':' . number_format($amount, 2, '.', '');
        if (WorkflowTransaction::where('event_key', $eventKey)->whereNotNull('journal_entry_id')->exists()) {
            return WorkflowTransaction::where('event_key', $eventKey)->first()->journalEntry;
        }

        $cashAccount = $treasuryId
            ? Account::whereHas('treasury', fn ($q) => $q->whereKey($treasuryId))->first()
            : Account::active()->where('account_type', 'asset')->first();
        $receivable = Account::active()->where('account_type', 'asset')->orderBy('id')->first();
        if (!$cashAccount || !$receivable) return null;

        return DB::transaction(function () use ($invoice, $amount, $cashAccount, $receivable, $eventKey) {
            $journal = JournalEntry::create([
                'entry_date' => now()->toDateString(),
                'description_ar' => 'تحصيل فاتورة مبيعات #' . $invoice->getKey(),
                'description_en' => 'Collection of sales invoice #' . $invoice->getKey(),
                'status' => 'posted',
            ]);
            $journal->lines()->createMany([
                ['account_id' => $cashAccount->id, 'debit' => $amount, 'credit' => 0, 'description' => 'تحصيل نقدي/بنكي'],
                ['account_id' => $receivable->id, 'debit' => 0, 'credit' => $amount, 'description' => 'تسوية حساب العميل'],
            ]);
            Account::whereKey($cashAccount->id)->increment('debit', $amount);
            Account::whereKey($receivable->id)->increment('credit', $amount);
            WorkflowTransaction::capture($eventKey, $invoice, 'sale_payment_collected', ['amount' => $amount], $journal->id, null, 'completed');
            return $journal;
        });
    }

    public function postSale(Model $invoice): ?JournalEntry
    {
        return $this->postInvoice($invoice, 'sale', (float) ($invoice->net_total ?? $invoice->total_amount ?? 0), (int) ($invoice->treasury_id ?? 0), (string) ($invoice->payment_method ?? 'cash'));
    }

    public function postPurchase(Model $invoice): ?JournalEntry
    {
        return $this->postInvoice($invoice, 'purchase', (float) ($invoice->total_amount ?? 0), (int) ($invoice->treasury_id ?? 0));
    }

    public function postReturn(Model $return, string $direction, float $amount, ?int $treasuryId = null): ?JournalEntry
    {
        return $this->postInvoice($return, $direction === 'sales_return' ? 'sales_return' : 'purchase_return', $amount, (int) ($treasuryId ?? 0));
    }

    public function reverseInvoice(Model $source, string $type): ?JournalEntry
    {
        $eventKey = 'financial-reversal:' . strtolower(class_basename($source)) . ':' . $source->getKey();
        $existing = WorkflowTransaction::where('event_key', $eventKey)->first();
        if ($existing?->journal_entry_id) return $existing->journalEntry;

        $original = $source->posting_journal_entry_id ? JournalEntry::with('lines')->find($source->posting_journal_entry_id) : null;
        return DB::transaction(function () use ($source, $type, $eventKey, $original) {
            $journal = null;
            if ($original) {
                $journal = JournalEntry::create(['entry_date' => now()->toDateString(), 'description_ar' => 'عكس ' . $this->label($type) . ' #' . $source->getKey(), 'description_en' => 'Reversal of ' . $this->label($type) . ' #' . $source->getKey(), 'status' => 'posted']);
                foreach ($original->lines as $line) {
                    $debit = (float) $line->credit;
                    $credit = (float) $line->debit;
                    $journal->lines()->create(['account_id' => $line->account_id, 'debit' => $debit, 'credit' => $credit, 'description' => 'عكس القيد الأصلي']);
                    Account::whereKey($line->account_id)->increment('debit', $debit);
                    Account::whereKey($line->account_id)->increment('credit', $credit);
                }
                $original->update(['status' => 'cancelled']);
            }

            $warehouseId = $source->warehouse_id ?? $source->invoice?->warehouse_id ?? $source->purchaseInvoice?->warehouse_id;
            $movementIds = [];
            if ($type === 'sale' && method_exists($source, 'items')) {
                foreach ($source->items as $item) {
                    $movement = app(InventoryMovementService::class)->apply([
                        'product_id' => $item->product_id,
                        'product_unit_id' => $item->product_unit_id ?? null,
                        'size_id' => $item->size_id ?? null,
                        'color_id' => $item->color_id ?? null,
                        'branch_id' => $source->branch_id ?? null,
                        'warehouse_id' => $warehouseId,
                        'movement_type' => 'sale_cancelled',
                        'quantity_delta' => (float) $item->quantity,
                        'reference_type' => $source::class,
                        'reference_id' => $source->getKey(),
                        'notes' => 'عكس حركة مخزون فاتورة المبيعات',
                    ]);
                    $movementIds[] = $movement->id;
                }
            } elseif ($warehouseId && method_exists($source, 'items')) {
                $movementType = in_array($type, ['sale', 'purchase_return'], true) ? 'receipt' : 'issue';
                foreach ($source->items as $item) {
                    $product = Product::lockForUpdate()->find($item->product_id);
                    if (!$product) continue;
                    $quantity = (float) $item->quantity;
                    if ($movementType === 'issue' && $product->stock < $quantity) {
                        throw new \RuntimeException("لا يمكن إلغاء الفاتورة؛ مخزون المنتج {$product->name} غير كافٍ لعكس عملية الشراء");
                    }
                    $movementType === 'receipt' ? $product->increment('stock', $quantity) : $product->decrement('stock', $quantity);
                    $warehouseStock = DB::table('product_warehouse')->where('product_id', $product->id)->where('warehouse_id', $warehouseId);
                    if ($movementType === 'receipt') {
                        $warehouseStock->increment('stock', $quantity);
                    } else {
                        $warehouseStock->decrement('stock', $quantity);
                    }
                    if (!empty($item->product_unit_id) && !empty($item->color_id)) {
                        $unit = DB::table('product_units')->where('product_id', $product->id)->where('unit_id', $item->product_unit_id)->first();
                        if ($unit) {
                            $colorStock = DB::table('product_unit_colors')->where('product_unit_id', $unit->id)->where('color_id', $item->color_id);
                            $movementType === 'receipt' ? $colorStock->increment('stock', $quantity) : $colorStock->decrement('stock', $quantity);
                        }
                    }
                    $unitCost = (float) ($product->cost ?? $item->price ?? 0);
                    $movement = InventoryMovement::create(['warehouse_id' => $warehouseId, 'product_id' => $product->id, 'reference_type' => $source::class, 'reference_id' => $source->getKey(), 'type' => $movementType, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'total_cost' => $quantity * $unitCost, 'note' => 'عكس الفاتورة وإلغاء المعاملة']);
                    $movementIds[] = $movement->id;
                }
            }
            WorkflowTransaction::capture($eventKey, $source, 'invoice_reversed', ['type' => $type, 'inventory_movement_ids' => $movementIds], $journal?->id, $movementIds[0] ?? null, 'completed');
            return $journal;
        });
    }

    private function postInvoice(Model $source, string $type, float $amount, int $treasuryId, ?string $paymentMethod = null): ?JournalEntry
    {
        if ($amount <= 0) return null;
        $eventKey = 'financial-posting:' . strtolower(class_basename($source)) . ':' . $source->getKey() . ':' . $type;
        if (WorkflowTransaction::where('event_key', $eventKey)->whereNotNull('journal_entry_id')->exists()) {
            return WorkflowTransaction::where('event_key', $eventKey)->first()->journalEntry;
        }

        $accounts = $this->accountsFor($type, $treasuryId, $paymentMethod);
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
            $movementIds = [];
            WorkflowTransaction::capture('financial-posting:' . strtolower(class_basename($source)) . ':' . $source->getKey() . ':' . $type, $source, $type . '_posted', ['amount' => $amount, 'inventory_movement_ids' => $movementIds], $journal->id, $movementIds[0] ?? null);
            return $journal;
        });
        return $journal;
    }

    private function accountsFor(string $type, int $treasuryId, ?string $paymentMethod = null): array
    {
        $treasuryAccount = $treasuryId
            ? Account::whereHas('treasury', fn ($q) => $q->whereKey($treasuryId))->first()
            : Account::active()->where('account_type', 'treasury')->first();
        $asset = Account::active()->where('account_type', 'asset')->first();
        $revenue = Account::active()->where('account_type', 'revenue')->first();
        $expense = Account::active()->where('account_type', 'expense')->first();
        return match ($type) {
            'sale' => ['debit' => (in_array($paymentMethod, ['credit', 'bank', 'bank_transfer'], true) ? $asset : ($treasuryAccount ?: $asset)), 'credit' => $revenue],
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
