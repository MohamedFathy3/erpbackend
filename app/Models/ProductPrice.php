<?php

namespace App\Models;

class ProductPrice extends BaseModel
{
    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'min_quantity' => 'decimal:6',
        'price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }
}
