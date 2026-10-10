<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حركة مخزون لمنتج سيارات (بالمتر): meters موجب = دخول، سالب = خروج.
 * type: initial | purchase | sale | return | adjustment
 */
class AutomotiveStockMovement extends BaseModel
{
    protected $table = 'automotive_stock_movements';
    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'meters' => 'decimal:3',
        'balance_after' => 'decimal:3',
        'unit_cost' => 'decimal:2',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(AutomotiveService::class, 'service_id');
    }
}