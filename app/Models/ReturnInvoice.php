<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ReturnInvoice extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $casts = [
        'total_amount'    => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    // ============================================================
    // العلاقات
    // ============================================================

    public function items()
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function shift()
    {
        return $this->belongsTo(CashierShift::class, 'shift_id');
    }

    // ============================================================
    // Boot
    // ============================================================

    protected static function booted()
    {
        static::creating(function ($model) {
            $model->return_number = 'RET-TEMP-' . (string) Str::uuid();
        });

        static::created(function (self $model) {
            $returnNumber = 'RET-' . str_pad((string) $model->getKey(), 6, '0', STR_PAD_LEFT);

            $numberAlreadyUsed = self::withoutGlobalScopes()
                ->where('return_number', $returnNumber)
                ->where($model->getKeyName(), '!=', $model->getKey())
                ->exists();

            if ($numberAlreadyUsed) {
                $returnNumber .= '-' . (string) Str::ulid();
            }

            $model->forceFill(['return_number' => $returnNumber])->saveQuietly();
        });
    }
}