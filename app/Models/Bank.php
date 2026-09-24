<?php

namespace App\Models;

class Bank extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
    public function account() { return $this->belongsTo(Account::class); }
}
