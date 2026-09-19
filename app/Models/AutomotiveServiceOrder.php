<?php

namespace App\Models;

use App\Traits\HasMedia;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotiveServiceOrder extends BaseModel
{
    use HasMedia;

    protected $table = 'automotive_service_orders';
    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'odometer' => 'decimal:2',
        'promised_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'inventory_consumed_at' => 'datetime',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(AutomotiveVehicle::class, 'vehicle_id'); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function advisor(): BelongsTo { return $this->belongsTo(Employee::class, 'advisor_id'); }
    public function items(): HasMany { return $this->hasMany(AutomotiveServiceOrderItem::class, 'service_order_id'); }
    public function technicianAssignments(): HasMany { return $this->hasMany(AutomotiveServiceOrderTechnician::class, 'service_order_id'); }
    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'automotive_service_order_technicians', 'service_order_id', 'employee_id')
            ->withPivot(['is_primary', 'assigned_at', 'completed_at'])->withTimestamps();
    }
}
