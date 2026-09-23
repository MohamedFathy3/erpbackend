<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalesInvoicePayment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function treasury()
    {
        return $this->belongsTo(Treasury::class);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }
}
