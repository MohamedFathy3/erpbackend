<?php

namespace App\Models;

use App\Helpers\Constants;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 *
 *
 * @property-read Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 * @method static Builder|BaseModel filter($filters = null, $filterOperator = '=')
 * @method static Builder|BaseModel newModelQuery()
 * @method static Builder|BaseModel newQuery()
 * @method static Builder|BaseModel onlyTrashed()
 * @method static Builder|BaseModel query()
 * @method static Builder|BaseModel withTrashed()
 * @method static Builder|BaseModel withoutTrashed()
 * @mixin Eloquent
 */
class BaseModel extends Model
{
    use SoftDeletes , LogsActivity;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $model = $builder->getModel();
            if ($model instanceof Tenant || !Schema::hasColumn($model->getTable(), 'tenant_id')) return;
            $user = auth()->user();
            if ($user && ($user->super_admin ?? false)) return;
            $tenantId = $user?->tenant_id;
            if (!$tenantId && app()->bound('currentTenantId')) $tenantId = app('currentTenantId');
            if ($tenantId) $builder->where($model->getTable().'.tenant_id', $tenantId);
        });
        static::creating(function (Model $model) {
            if (!Schema::hasColumn($model->getTable(), 'tenant_id') || $model->tenant_id) return;
            $actor = auth()->user();
            if ($actor && !($actor->super_admin ?? false)) $model->tenant_id = $actor->tenant_id;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*'])->logOnlyDirty();
    }
    public function scopeFilter($builder, $filters = null, $filterOperator = "=")
    {
        if (isset($filters) && is_array($filters)) {
            foreach ($filters as $field => $value) {
                if ($value == Constants::NULL)
                    $builder->whereNull($field);
                elseif ($value == Constants::NOT_NULL)
                    $builder->whereNotNull($field);
                elseif (is_array($value))
                    $builder->whereIn($field, $value);
                elseif ($filterOperator == "like")
                    $builder->where($field, $filterOperator, '%' . $value . '%');
                else
                    $builder->where($field, $value);
            }
        }
        return $builder;
    }
}
