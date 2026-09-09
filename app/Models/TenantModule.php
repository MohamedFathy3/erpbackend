<?php
namespace App\Models;

class TenantModule extends BaseModel
{
    protected $table = 'tenant_modules';
    protected $guarded = ['id'];
    protected $casts = ['is_enabled' => 'boolean'];
    public function tenant() { return $this->belongsTo(Tenant::class); }

    public static function available(): array
    {
        return [
            'dashboard','pos','inventory','purchasing','sales','finance','hr','crm','reports',
            'settings','industries','manufacturing','projects','workflow','email','whatsapp',
            'google_calendar','google_drive','tasks','manufacturing_setup','product_ledger',
        ];
    }
}
