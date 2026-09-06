<?php
namespace App\Models;
class ManufacturingOrder extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['planned_start_date' => 'date', 'planned_end_date' => 'date', 'planned_quantity' => 'decimal:4', 'produced_quantity' => 'decimal:4', 'planned_cost' => 'decimal:2', 'actual_cost' => 'decimal:2'];
    public function product() { return $this->belongsTo(Product::class); }
    public function bom() { return $this->belongsTo(ManufacturingBom::class, 'bom_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function inspections() { return $this->hasMany(ManufacturingQualityInspection::class); }
}
