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

class BaseModel extends Model
{
    use SoftDeletes, LogsActivity;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $model = $builder->getModel();
            if ($model instanceof Tenant || !Schema::hasColumn($model->getTable(), 'tenant_id')) {
                return;
            }

            $user = auth()->user();
            if ($user && !((bool) ($user->super_admin ?? false))) {
                $tenantId = $user->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);
                if ($tenantId) $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function (Model $model): void {
            if (!Schema::hasColumn($model->getTable(), 'tenant_id') || $model instanceof Tenant || $model->tenant_id) {
                return;
            }

            $user = auth()->user();
            if ($user && !((bool) ($user->super_admin ?? false)) && $user->tenant_id) {
                $model->tenant_id = $user->tenant_id;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*'])->logOnlyDirty();
    }

    public function scopeFilter($builder, $filters = null, $filterOperator = '=')
    {
        if (isset($filters) && is_array($filters)) {
            foreach ($filters as $field => $value) {
                if ($value == Constants::NULL) $builder->whereNull($field);
                elseif ($value == Constants::NOT_NULL) $builder->whereNotNull($field);
                elseif (is_array($value)) $builder->whereIn($field, $value);
                elseif ($filterOperator == 'like') $builder->where($field, $filterOperator, '%' . $value . '%');
                else $builder->where($field, $value);
            }
        }
        return $builder;
    }
}
