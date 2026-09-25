<?php
namespace App\Models;

class AttendanceRule extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['deduct_early_leave' => 'boolean', 'is_active' => 'boolean', 'late_deduction_value' => 'decimal:4', 'absence_deduction_value' => 'decimal:4'];
}
