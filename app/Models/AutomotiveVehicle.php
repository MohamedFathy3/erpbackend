<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotiveVehicle extends BaseModel
{
    protected $table = 'automotive_vehicles';
    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'model_year' => 'integer',
        'current_mileage' => 'decimal:2',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function serviceOrders(): HasMany { return $this->hasMany(AutomotiveServiceOrder::class, 'vehicle_id'); }
}
