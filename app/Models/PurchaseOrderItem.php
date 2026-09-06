<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $guarded = ['id'];

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

       public function color()
    {
        return $this->belongsTo(Color::class);
    }

    

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
    
}

