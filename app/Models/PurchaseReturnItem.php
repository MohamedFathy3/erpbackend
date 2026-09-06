<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    protected $guarded = ['id'];

    // ✅ العلاقة مع المرتجع (الأهم)
    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'product_unit_id');
    }

    // public function variant()
    // {
    //     return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    // }
}