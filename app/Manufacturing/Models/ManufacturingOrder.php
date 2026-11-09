<?php
namespace App\Manufacturing\Models;
use App\Models\BaseModel;
use App\Models\Product;

class ManufacturingOrder extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = [
        'planned_quantity' => 'decimal:6',
        'produced_quantity' => 'decimal:6',
        'scrapped_quantity' => 'decimal:6',
        'reserved_quantity' => 'decimal:6',
        'planned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
        'actual_cost' => 'decimal:6',
        'dimensions' => 'array',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function productUnit() { return $this->belongsTo(\App\Models\ProductUnit::class); }
    public function color() { return $this->belongsTo(\App\Models\Color::class); }
    public function bomVersion() { return $this->belongsTo(BomVersion::class); }
    public function materials() { return $this->hasMany(ManufacturingOrderMaterial::class); }
    public function operations() { return $this->hasMany(ManufacturingOperation::class)->orderBy('sequence'); }
    public function statusHistory() { return $this->hasMany(ManufacturingOrderStatusHistory::class)->orderBy('id'); }
    public function movements() { return $this->hasMany(ManufacturingStockMovement::class); }
    public function costs() { return $this->hasMany(ManufacturingCostEntry::class); }
    public function qualityInspections() { return $this->hasMany(QualityInspection::class); }
}
