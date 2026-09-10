<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'name_ar',           // ✅ إضافة الاسم بالعربي
        'account_type',      // ✅ إضافة نوع الحساب
        'debit',
        'credit',
        'balance',
        'parent_id',
        'is_header',
        'is_active',
        'normal_balance',
        'description',
        'branch_id',
        'company_id'
    ];

    protected $casts = [
        'debit' => 'float',
        'credit' => 'float',
        'balance' => 'float',
        'is_header' => 'boolean',
        'is_active' => 'boolean',
    ];

    // =========================
    // ✅ العلاقات
    // =========================

    /**
     * العلاقة مع الحساب الأب
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    /**
     * العلاقة مع الحسابات الفرعية
     */
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    /**
     * العلاقة العودية لجلب كل الأبناء والأحفاد
     */
    public function childrenRecursive()
    {
        return $this->hasMany(Account::class, 'parent_id')->with('childrenRecursive');
    }

    /**
     * العلاقة العودية لجلب كل الآباء والأجداد
     */
    public function parentRecursive()
    {
        return $this->belongsTo(Account::class, 'parent_id')->with('parentRecursive');
    }

    /**
     * ✅ العلاقة مع الخزينة (لو الحساب من نوع treasury)
     */
    public function treasury(): HasOne
    {
        return $this->hasOne(Treasury::class, 'account_id');
    }

    /**
     * العلاقة مع بنود القيد اليومي
     */
    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(\App\Models\JournalEntryLine::class);
    }

    // =========================
    // ✅ Scopes
    // =========================

    /**
     * حساب نشط
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * حسب نوع الحساب
     */
    public function scopeByType($query, $type)
    {
        return $query->where('account_type', $type);
    }

    /**
     * حسابات الخزائن
     */
    public function scopeTreasury($query)
    {
        return $query->where('account_type', 'treasury');
    }

    // =========================
    // ✅ Accessors
    // =========================

    /**
     * جلب اسم الحساب حسب اللغة
     */
    public function getDisplayNameAttribute(): string
    {
        $locale = app()->getLocale();
        if ($locale === 'ar' && $this->name_ar) {
            return $this->name_ar;
        }
        return $this->name;
    }

    /**
     * جلب اسم الحساب مع الكود
     */
    public function getCodeNameAttribute(): string
    {
        return "{$this->code} - {$this->display_name}";
    }

    /**
     * جلب نوع الحساب بالعربي
     */
    public function getAccountTypeLabelAttribute(): string
    {
        $types = [
            'asset' => 'أصل',
            'liability' => 'خصم',
            'equity' => 'حقوق ملكية',
            'revenue' => 'إيراد',
            'expense' => 'مصروف',
            'treasury' => 'خزينة',
        ];
        return $types[$this->account_type] ?? $this->account_type;
    }

    // =========================
    // ✅ Helpers
    // =========================

    /**
     * دالة مساعدة لمعرفة مستوى الحساب (عمق الشجرة)
     */
    public function getLevelAttribute()
    {
        $level = 0;
        $parent = $this->parent;
        while ($parent) {
            $level++;
            $parent = $parent->parent;
        }
        return $level;
    }

    /**
     * دالة لجلب كل الأحفاد (جميع المستويات)
     */
    public function getAllDescendants()
    {
        $descendants = collect();

        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->getAllDescendants());
        }

        return $descendants;
    }

    /**
     * هل الحساب من نوع خزينة؟
     */
    public function isTreasury(): bool
    {
        return $this->account_type === 'treasury';
    }

    /**
     * جلب حساب الخزينة المرتبط
     */
    public function getTreasuryBalanceAttribute(): float
    {
        if ($this->isTreasury() && $this->treasury) {
            return $this->treasury->balance;
        }
        return 0;
    }

    /**
     * جلب رصيد الحساب من حركات القيد
     */
    public function getBalanceFromEntries(): float
    {
        $totalDebit = $this->journalEntryLines()->sum('debit');
        $totalCredit = $this->journalEntryLines()->sum('credit');
        
        if ($this->normal_balance === 'debit') {
            return $totalDebit - $totalCredit;
        }
        return $totalCredit - $totalDebit;
    }

    // =========================
    // ✅ Static Helpers
    // =========================

    /**
     * البحث عن حساب الخزينة المرتبط بـ treasury_id
     */
    public static function findTreasuryAccount($treasuryId): ?Account
    {
        return Account::where('code', 'treasury_' . $treasuryId)
            ->orWhere('account_type', 'treasury')
            ->first();
    }
}