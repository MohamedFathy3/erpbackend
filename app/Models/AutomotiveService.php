<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotiveService extends BaseModel
{
    protected $table = 'automotive_services';
    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
        'estimated_minutes' => 'integer',
        'warranty_eligible' => 'boolean',
        'active' => 'boolean',
    ];

    public function orderItems(): HasMany
    {
        return $this->hasMany(AutomotiveServiceOrderItem::class, 'service_id');
    }
}
