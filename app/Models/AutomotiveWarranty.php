<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotiveWarranty extends BaseModel
{
    protected $table = 'automotive_warranties';
    protected $guarded = ['id', 'tenant_id'];
    protected $casts = ['starts_at' => 'date', 'ends_at' => 'date', 'mileage_limit' => 'decimal:2'];
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(AutomotiveVehicle::class, 'vehicle_id'); }
    public function serviceOrder(): BelongsTo { return $this->belongsTo(AutomotiveServiceOrder::class, 'service_order_id'); }
}
