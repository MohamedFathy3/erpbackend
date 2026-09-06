<?php
namespace App\Models;
class ManufacturingWorkCenter extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean', 'hourly_rate' => 'decimal:4', 'capacity_hours_per_day' => 'decimal:2'];
}
