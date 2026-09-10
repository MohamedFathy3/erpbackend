<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class SalesRepresentative extends BaseModel
{
    use HasApiTokens;

    protected $guarded = ['id'];
    protected $hidden = ['password'];

    protected $casts = [
        'active' => 'boolean'
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function invoices()
    {
        return $this->hasMany(SalesInvoice::class, 'sales_representative_id');
    }
    
}
