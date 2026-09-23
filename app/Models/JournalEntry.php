<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToTenant;
    protected $guarded = ['id'];

    protected $casts = [
        'entry_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'posted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    // =========================
    // ✅ العلاقات
    // =========================

    /**
     * العلاقة مع بنود القيد
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * ✅ العلاقة مع الخزينة
     */
    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'treasury_id');
    }

    /**
     * العلاقة مع المستخدم الذي أنشأ القيد
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function fiscalPeriod(): BelongsTo { return $this->belongsTo(FinancialPeriod::class, 'fiscal_period_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(CostCenter::class, 'cost_center_id'); }
    public function reversalOf(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'reversal_of_id'); }
    public function reversals(): HasMany { return $this->hasMany(JournalEntry::class, 'reversal_of_id'); }

    // =========================
    // ✅ Accessors (محسوبات)
    // =========================

    public function getTotalDebitAttribute()
    {
        return $this->lines()->sum('debit');
    }

    public function getTotalCreditAttribute()
    {
        return $this->lines()->sum('credit');
    }

    public function getIsBalancedAttribute(): bool
    {
        return $this->total_debit == $this->total_credit;
    }

    // =========================
    // ✅ Scopes
    // =========================

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeByTreasury($query, $treasuryId)
    {
        return $query->where('treasury_id', $treasuryId);
    }

    // =========================
    // ✅ Helpers
    // =========================

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function canBePosted(): bool
    {
        return $this->isDraft() && $this->is_balanced;
    }

    public function canBeCancelled(): bool
    {
        return !$this->isPosted();
    }
}
