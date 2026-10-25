<?php

namespace App\Models;

class ProductBranchPrice extends BaseModel
{
    protected $table = 'product_branch_prices';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
