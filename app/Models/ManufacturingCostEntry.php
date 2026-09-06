<?php
namespace App\Models;
class ManufacturingCostEntry extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:2'];
    public function order() { return $this->belongsTo(ManufacturingOrder::class, 'manufacturing_order_id'); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class); }
}
