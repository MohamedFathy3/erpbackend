<?php

namespace App\Notifications;

use App\Models\InventoryTransferRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InventoryTransferRequestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public InventoryTransferRequest $transferRequest,
        public string $event = 'created',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $request = $this->transferRequest->loadMissing(['product', 'fromBranch', 'toBranch']);
        $productName = $request->product?->name_ar ?: $request->product?->name ?: 'منتج';
        $message = match ($this->event) {
            'approved' => "تم اعتماد طلب نقل {$productName} إلى فرعك.",
            'rejected' => "تم رفض طلب نقل {$productName}.",
            default => "طلب نقل {$productName} من فرع {$request->fromBranch?->name} إلى فرع {$request->toBranch?->name}.",
        };

        return [
            'type' => 'inventory_transfer_request',
            'event' => $this->event,
            'transfer_request_id' => $request->id,
            'title' => 'طلب نقل مخزون',
            'message' => $message,
            'url' => '/inventory-transfer-requests',
            'product_id' => $request->product_id,
            'quantity' => (float) $request->quantity,
        ];
    }
}
