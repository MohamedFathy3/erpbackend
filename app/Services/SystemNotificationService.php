<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\TreasuryTransaction;
use App\Notifications\SystemEventNotification;
use Illuminate\Database\Eloquent\Model;

class SystemNotificationService
{
    public function notifyTenantAdmins(
        ?int $tenantId,
        string $eventKey,
        string $category,
        string $title,
        string $message,
        string $url,
        array $meta = [],
    ): void {
        if (!$tenantId) return;

        $admins = Admin::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->whereNull('deleted_at')
            ->get();

        foreach ($admins as $admin) {
            $alreadySent = $admin->notifications()
                ->where('type', SystemEventNotification::class)
                ->where('data', 'like', '%"event_key":"' . addcslashes($eventKey, '%_\\') . '"%')
                ->exists();
            if ($alreadySent) continue;

            $admin->notify(new SystemEventNotification($eventKey, $category, $title, $message, $url, $meta));
        }
    }

    public function invoiceCreated(Model $invoice, string $kind): void
    {
        $isPurchase = $kind === 'purchase';
        $number = $invoice->invoice_number ?? $invoice->getKey();
        $this->notifyTenantAdmins(
            (int) ($invoice->tenant_id ?? 0),
            'invoice-created:' . strtolower(class_basename($invoice)) . ':' . $invoice->getKey(),
            'info',
            $isPurchase ? 'فاتورة مشتريات جديدة' : 'فاتورة مبيعات جديدة',
            ($isPurchase ? 'تم إنشاء فاتورة المشتريات رقم ' : 'تم إنشاء فاتورة المبيعات رقم ') . $number . '.',
            $isPurchase ? '/purchasing' : '/sales',
            ['invoice_id' => $invoice->getKey(), 'invoice_number' => (string) $number, 'source' => $invoice::class],
        );
    }

    public function journalPosted(JournalEntry $entry): void
    {
        if ($entry->status !== 'posted') return;
        $source = strtolower(class_basename((string) $entry->source_type));
        $category = 'info';
        $title = 'قيد محاسبي جديد';
        $message = 'تم ترحيل قيد محاسبي رقم ' . ($entry->entry_number ?: $entry->id) . '.';
        $url = '/finance?tab=accounting';

        if (str_contains($source, 'sales') || $source === 'invoice') {
            $title = 'قيد مبيعات جديد';
            $message = 'تم ترحيل قيد فاتورة مبيعات رقم ' . ($entry->source_id ?: $entry->id) . '.';
            $url = '/sales';
        } elseif (str_contains($source, 'purchase')) {
            $title = 'قيد مشتريات جديد';
            $message = 'تم ترحيل قيد فاتورة/دفعة مشتريات رقم ' . ($entry->source_id ?: $entry->id) . '.';
            $url = '/purchasing';
        } elseif (str_contains($source, 'payroll') || str_contains($source, 'advance') || str_contains($source, 'employee')) {
            $title = 'حركة مالية للموظفين';
            $message = $entry->description_ar ?: ('تم ترحيل قيد مالي للموظفين #' . $entry->source_id . '.');
            $url = '/employee-financial-reports';
        }

        $this->notifyTenantAdmins(
            (int) ($entry->tenant_id ?? 0),
            'journal-posted:' . $entry->id,
            $category,
            $title,
            $message,
            $url,
            ['journal_entry_id' => $entry->id, 'source_type' => $entry->source_type, 'source_id' => $entry->source_id],
        );
    }

    public function lowStock(Product $product, InventoryMovement $movement, float $previousStock): void
    {
        $threshold = max(0, (float) ($product->reorder_level ?? 0));
        $currentStock = (float) ($product->stock ?? 0);
        if ($previousStock <= $threshold || $currentStock > $threshold) return;

        $this->notifyTenantAdmins(
            (int) ($product->tenant_id ?? 0),
            'low-stock:' . $movement->id,
            'warning',
            'مخزون منتج وصل لحد إعادة الطلب',
            'رصيد المنتج ' . ($product->name_ar ?: $product->name ?: ('#' . $product->id)) . ' أصبح ' . $currentStock . '، وحد إعادة الطلب ' . $threshold . '.',
            '/inventory',
            ['product_id' => $product->id, 'inventory_movement_id' => $movement->id, 'stock' => $currentStock, 'reorder_level' => $threshold],
        );
    }

    public function treasuryTransactionCreated(TreasuryTransaction $transaction): void
    {
        $treasury = $transaction->treasury;
        if (!$treasury) return;
        $threshold = (float) ($treasury->alert_below_balance ?? 0);
        $balance = (float) $treasury->balance;
        $amount = (float) $transaction->amount;
        $previousBalance = $transaction->type === 'out' ? $balance + $amount : $balance - $amount;
        if ($threshold <= 0) {
            $referenceBalance = $transaction->type === 'in' ? $balance : $previousBalance;
            if ($referenceBalance <= 0) return;
            $threshold = round($referenceBalance * 0.10, 2);
            $treasury->forceFill(['alert_below_balance' => $threshold])->save();
        }
        if ($previousBalance <= $threshold || $balance > $threshold) return;

        $this->notifyTenantAdmins(
            (int) ($treasury->tenant_id ?? 0),
            'treasury-low-balance:' . $transaction->id,
            'warning',
            'رصيد الخزينة منخفض',
            'رصيد خزينة ' . ($treasury->name ?: ('#' . $treasury->id)) . ' وصل إلى ' . number_format($balance, 2) . ' ' . $treasury->currency . '، والحد المحدد ' . number_format($threshold, 2) . '.',
            '/finance',
            ['treasury_id' => $treasury->id, 'treasury_transaction_id' => $transaction->id, 'balance' => $balance, 'threshold' => $threshold],
        );
    }

    public function transferRequested(Model $transfer): void
    {
        $productName = $transfer->product?->name_ar ?: $transfer->product?->name ?: ('#' . $transfer->product_id);
        $this->notifyTenantAdmins(
            (int) ($transfer->tenant_id ?? 0),
            'inventory-transfer-request:' . $transfer->id,
            'warning',
            'طلب نقل مخزون جديد',
            'تم طلب نقل ' . $productName . ' بكمية ' . (float) $transfer->quantity . ' بين المخازن/الفروع ويحتاج مراجعة.',
            '/inventory-transfer-requests',
            ['transfer_request_id' => $transfer->id, 'product_id' => $transfer->product_id, 'quantity' => (float) $transfer->quantity],
        );
    }
}
