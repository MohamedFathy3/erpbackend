<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends BaseModel
{
    protected $guarded = ['id'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
    
    public function purchaseInvoices()
    {
        return $this->hasMany(PurchaseInvoice::class);
    }
}
