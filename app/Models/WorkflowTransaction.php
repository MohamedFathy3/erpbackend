<?php
namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WorkflowTransaction extends Model
{
    use BelongsToTenant;
    protected $guarded = ['id'];
    protected $casts = ['payload' => 'array', 'occurred_at' => 'datetime'];

    public static function capture(string $eventKey, Model $source, string $event, array $payload = [], ?int $journalEntryId = null, ?int $inventoryMovementId = null, string $status = 'completed'): self
    {
        return static::updateOrCreate(['event_key' => $eventKey], [
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
            'event' => $event,
            'status' => $status,
            'journal_entry_id' => $journalEntryId,
            'inventory_movement_id' => $inventoryMovementId,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    public function journalEntry() { return $this->belongsTo(JournalEntry::class); }
    public function inventoryMovement() { return $this->belongsTo(InventoryMovement::class); }
}
