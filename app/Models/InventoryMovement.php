<?php
namespace App\Models;
class InventoryMovement extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:2'];
    public function product() { return $this->belongsTo(Product::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function manufacturingOrder() { return $this->belongsTo(ManufacturingOrder::class); }
}
