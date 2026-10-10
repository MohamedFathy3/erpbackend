<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotiveService extends BaseModel
{
    protected $table = 'automotive_services';
    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'small_vehicle_quantity' => 'decimal:3',
        'large_vehicle_quantity' => 'decimal:3',
        'small_vehicle_price' => 'decimal:2',
        'large_vehicle_price' => 'decimal:2',
        'stock_quantity' => 'decimal:3',
        'estimated_cost' => 'decimal:2',
        'estimated_minutes' => 'integer',
        'warranty_eligible' => 'boolean',
        'active' => 'boolean',
        'has_fixed_price' => 'boolean',
    ];

    protected $appends = ['is_custom_priced'];

    public function getIsCustomPricedAttribute(): bool
    {
        return !$this->has_fixed_price;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(AutomotiveServiceOrderItem::class, 'service_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(AutomotiveStockMovement::class, 'service_id');
    }
}