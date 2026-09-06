<?php
namespace App\Models;
class ManufacturingBomItem extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['quantity' => 'decimal:4', 'scrap_percent' => 'decimal:3'];
    public function bom() { return $this->belongsTo(ManufacturingBom::class, 'bom_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
