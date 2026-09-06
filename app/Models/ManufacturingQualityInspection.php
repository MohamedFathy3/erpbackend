<?php
namespace App\Models;
class ManufacturingQualityInspection extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['accepted_quantity' => 'decimal:4', 'rejected_quantity' => 'decimal:4', 'inspected_at' => 'datetime'];
    public function order() { return $this->belongsTo(ManufacturingOrder::class, 'manufacturing_order_id'); }
}
