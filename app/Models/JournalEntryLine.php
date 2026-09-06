<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    // =========================
    // ✅ العلاقات
    // =========================

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    // =========================
    // ✅ Accessors
    // =========================

    public function getAmountAttribute()
    {
        return $this->debit > 0 ? $this->debit : $this->credit;
    }

    public function getTypeAttribute(): string
    {
        return $this->debit > 0 ? 'debit' : 'credit';
    }

    // =========================
    // ✅ Helpers
    // =========================

    public function isDebit(): bool
    {
        return $this->debit > 0;
    }

    public function isCredit(): bool
    {
        return $this->credit > 0;
    }
}