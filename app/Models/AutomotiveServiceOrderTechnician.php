<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotiveServiceOrderTechnician extends BaseModel
{
    protected $table = 'automotive_service_order_technicians';
    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'is_primary' => 'boolean',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function order(): BelongsTo { return $this->belongsTo(AutomotiveServiceOrder::class, 'service_order_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
