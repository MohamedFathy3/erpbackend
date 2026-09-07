<?php

namespace App\Models;

class ManufacturingOrderOperation extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['planned_hours' => 'decimal:2', 'actual_hours' => 'decimal:2', 'hourly_rate' => 'decimal:4', 'actual_cost' => 'decimal:2'];
    public function order() { return $this->belongsTo(ManufacturingOrder::class, 'manufacturing_order_id'); }
    public function workCenter() { return $this->belongsTo(ManufacturingWorkCenter::class, 'work_center_id'); }
}
