<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ReturnInvoice extends Model
{
    use BelongsToTenant;
    protected $guarded = ['id'];

    public function items()
    {
        return $this->hasMany(ReturnItem::class);
    }


    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            // The auto-increment id is not available until after INSERT. Use a
            // collision-proof placeholder so concurrent inserts never calculate
            // the same number from max(id).
            $model->return_number = 'RET-TEMP-' . (string) Str::uuid();
        });

        static::created(function (self $model) {
            $returnNumber = 'RET-' . str_pad((string) $model->getKey(), 6, '0', STR_PAD_LEFT);

            // Older tenant-scoped rows can already own the number that matches
            // this row's id. Keep the readable format, but make that legacy
            // collision unique without relying on a non-atomic max(id) query.
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

        public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

}
