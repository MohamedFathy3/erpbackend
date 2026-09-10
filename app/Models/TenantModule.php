<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModule extends BaseModel
{
    public static function available(): array
    {
        return [
            'dashboard', 'pos', 'inventory', 'purchasing', 'sales', 'finance',
            'hr', 'crm', 'reports', 'settings', 'industries', 'manufacturing',
            'projects', 'workflow', 'email', 'whatsapp', 'google_calendar',
            'google_drive', 'tasks', 'manufacturing_setup', 'product_ledger',
        ];
    }

    protected $guarded = ['id'];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
