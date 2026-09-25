<?php
namespace App\Models;

class BiometricLog extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['recorded_at' => 'datetime', 'payload' => 'array'];
    public function device() { return $this->belongsTo(BiometricDevice::class, 'device_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
}
