<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayroll extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date',
        'paid_at' => 'datetime', 'base_salary' => 'decimal:2', 'allowances' => 'decimal:2',
        'deductions' => 'decimal:2', 'advance_deductions' => 'decimal:2', 'net_salary' => 'decimal:2',
        'adjustments' => 'array',
    ];
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}

