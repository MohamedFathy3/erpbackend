<?php
namespace App\Services;
use App\Models\InventoryLog;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
class InventoryAdjustmentPostingService
{
    public function __construct(private readonly AccountLedgerService $ledger) {}
    public function post(InventoryLog $log, float $unitCost): ?JournalEntry
    {
        $quantity = (float) $log->difference;
        $amount = round(abs($quantity) * $unitCost, 2);
        if ($amount <= 0 || $quantity == 0.0) return null;
        return DB::transaction(function () use ($log, $quantity, $amount): JournalEntry {
            $inventory = app(SubledgerPostingService::class)->detailAccount('asset', '1200-INVENTORY', 'مخزون البضائع', 'Merchandise inventory', '1200', 'المخزون', 'Inventory');
            $adjustment = $quantity > 0
                ? $this->ledger->defaultAccount('revenue', 'INVENTORY-ADJUSTMENTS', 'فروقات جرد دائنة', 'Inventory adjustment gains')
                : $this->ledger->defaultAccount('expense', 'INVENTORY-ADJUSTMENTS', 'فروقات جرد مدينة', 'Inventory adjustment losses');
            $entry = JournalEntry::create([
                'entry_date' => now()->toDateString(),
                'description_ar' => 'قيد تسوية جرد #' . $log->id,
                'description_en' => 'Inventory count adjustment #' . $log->id,
                'status' => 'posted',
                'source_type' => InventoryLog::class,
                'source_id' => $log->id,
                'branch_id' => $log->warehouse?->branch_id,
                'posted_by' => auth()->id(),
                'posted_at' => now(),
                'tenant_id' => $log->tenant_id,
            ]);
            $lines = $quantity > 0
                ? [[$inventory, $amount, 0, 'زيادة فعلية من الجرد'], [$adjustment, 0, $amount, 'إيراد فروقات الجرد']]
                : [[$adjustment, $amount, 0, 'خسارة فروقات الجرد'], [$inventory, 0, $amount, 'نقص فعلي من الجرد']];
            foreach ($lines as [$account, $debit, $credit, $description]) {
                $entry->lines()->create(['account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'description' => $description]);
                $this->ledger->updateTotals($account, $debit, $credit);
            }
            $log->update(['journal_entry_id' => $entry->id]);
            return $entry->load('lines');
        });
    }
}
