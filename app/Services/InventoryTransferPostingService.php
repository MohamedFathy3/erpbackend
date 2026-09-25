<?php

namespace App\Services;

use App\Models\InventoryTransferRequest;
use App\Models\JournalEntry;
use App\Models\WorkflowTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryTransferPostingService
{
    public function __construct(private readonly AccountLedgerService $ledger)
    {
    }

    public function post(InventoryTransferRequest $request, float $unitCost, array $movementIds): JournalEntry
    {
        return DB::transaction(function () use ($request, $unitCost, $movementIds): JournalEntry {
            $transfer = InventoryTransferRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($transfer->journal_entry_id) {
                return JournalEntry::with('lines')->findOrFail($transfer->journal_entry_id);
            }

            $quantity = (float) $transfer->quantity;
            $amount = round($quantity * $unitCost, 2);
            if ($unitCost <= 0 || $amount <= 0) {
                throw ValidationException::withMessages([
                    'product_id' => 'لا يمكن ترحيل نقل المخزون دون تكلفة موجبة للمنتج. حدّث تكلفة المنتج أو تكلفة المخزن ثم أعد المحاولة.',
                ]);
            }

            $inventory = app(SubledgerPostingService::class)->detailAccount(
                'asset',
                '1200-INVENTORY',
                'مخزون البضائع',
                'Merchandise inventory',
                '1200',
                'المخزون',
                'Inventory',
            );

            $journal = JournalEntry::create([
                'entry_date' => now()->toDateString(),
                'description_ar' => 'تحويل مخزون بين الفروع #' . $transfer->id,
                'description_en' => 'Branch inventory transfer #' . $transfer->id,
                'status' => 'posted',
                'source_type' => InventoryTransferRequest::class,
                'source_id' => $transfer->id,
                'posted_by' => auth()->id(),
                'posted_at' => now(),
                'tenant_id' => $transfer->tenant_id,
            ]);

            $journal->lines()->createMany([
                [
                    'account_id' => $inventory->id,
                    'branch_id' => $transfer->to_branch_id,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'إضافة مخزون للفرع المستلم · كمية ' . $quantity,
                ],
                [
                    'account_id' => $inventory->id,
                    'branch_id' => $transfer->from_branch_id,
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'خفض مخزون الفرع المصدر · كمية ' . $quantity,
                ],
            ]);
            $this->ledger->updateTotals($inventory, $amount, 0);
            $this->ledger->updateTotals($inventory, 0, $amount);

            $transfer->update(['journal_entry_id' => $journal->id]);
            WorkflowTransaction::capture(
                'inventory-transfer-approved:' . $transfer->id,
                $transfer,
                'inventory_transfer_approved',
                [
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                    'from_branch_id' => $transfer->from_branch_id,
                    'to_branch_id' => $transfer->to_branch_id,
                    'inventory_movement_ids' => $movementIds,
                ],
                $journal->id,
                $movementIds[0] ?? null,
                'completed',
            );

            return $journal->load('lines');
        });
    }
}
