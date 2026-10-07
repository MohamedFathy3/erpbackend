<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ReturnItem extends Model
{
    use BelongsToTenant;
     protected $guarded = ['id'];

     public function returnInvoice()
     {
         return $this->belongsTo(ReturnInvoice::class);
     }

     public function product()
     {
         return $this->belongsTo(Product::class);
     }
}
