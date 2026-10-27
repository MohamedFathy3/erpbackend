<?php
namespace App\Models;

class BiometricDevice extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'adms_registered_at' => 'datetime',
    ];
    public function branch() { return $this->belongsTo(Branch::class); }
    public function logs() { return $this->hasMany(BiometricLog::class, 'device_id'); }
}
