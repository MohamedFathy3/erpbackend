<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotiveVisit extends BaseModel
{
    protected $table = 'automotive_visits';
    protected $guarded = ['id', 'tenant_id'];
    protected $casts = ['scheduled_at' => 'datetime', 'checked_in_at' => 'datetime', 'checked_out_at' => 'datetime'];
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(AutomotiveVehicle::class, 'vehicle_id'); }
    public function serviceOrder(): BelongsTo { return $this->belongsTo(AutomotiveServiceOrder::class, 'service_order_id'); }
    public function advisor(): BelongsTo { return $this->belongsTo(Employee::class, 'advisor_id'); }
}
