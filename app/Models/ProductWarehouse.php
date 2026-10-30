<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductWarehouse extends Model
{
    use BelongsToTenant;

    protected $table = 'product_warehouse';

    protected $fillable = [
            'tenant_id',
        'product_id',
        'warehouse_id',
        'stock',
        'cost',
    ];

    protected $casts = [
        'product_id'   => 'integer',
        'warehouse_id' => 'integer',
        'stock'        => 'float',      // ✅ float مش integer
        'cost'         => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}