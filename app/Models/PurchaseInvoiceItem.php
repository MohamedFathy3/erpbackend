<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Unit;
use App\Models\Color;
use App\Models\Product;
use App\Models\PurchaseInvoice;

class PurchaseInvoiceItem extends Model
{
    // ✅ أضف الأعمدة الجديدة في $fillable
    protected $fillable = [
        'purchase_invoice_id',
        'product_id',
        'product_unit_id',
        'color_id',           // ✅ جديد
        // 'product_variant_id', // ✅ جديد
        'quantity',
        'price',
        'discount',
        'tax',
        'total',
    ];

    // العلاقات
    public function invoice()
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'product_unit_id');
    }

    public function color()
    {
        return $this->belongsTo(Color::class, 'color_id');
    }

   
}