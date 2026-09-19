<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotiveServiceOrderItem extends BaseModel
{
    protected $table = 'automotive_service_order_items';
    public $timestamps = true;
    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'requires_approval' => 'boolean',
        'approved' => 'boolean',
    ];

    public function order(): BelongsTo { return $this->belongsTo(AutomotiveServiceOrder::class, 'service_order_id'); }
    public function service(): BelongsTo { return $this->belongsTo(AutomotiveService::class, 'service_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
