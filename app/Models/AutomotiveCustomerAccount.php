<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class AutomotiveCustomerAccount extends BaseModel implements Authenticatable
{
    use AuthenticatableTrait, HasApiTokens;

    protected $table = 'automotive_customer_accounts';
    protected $guarded = ['id', 'tenant_id'];
    protected $hidden = ['password'];
    protected $casts = ['active' => 'boolean', 'last_login_at' => 'datetime'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
