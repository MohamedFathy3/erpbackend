<?php
namespace App\Models;
class ManufacturingBom extends BaseModel
{
    protected $guarded = ['id'];
    public function product() { return $this->belongsTo(Product::class); }
    public function items() { return $this->hasMany(ManufacturingBomItem::class, 'bom_id'); }
}
