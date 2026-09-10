<?php

namespace App\Models;

use App\Helpers\Constants;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use LogicException;
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
                // Fail closed: an authenticated account without a tenant must never
                // receive rows from every workspace.
                if ($tenantId) {
                    $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
                } else {
                    $builder->whereRaw('1 = 0');
                }
            }
        });

        static::creating(function (Model $model): void {
            if (!Schema::hasColumn($model->getTable(), 'tenant_id') || $model instanceof Tenant || $model->tenant_id) {
                return;
            }

            $user = auth()->user();
            $tenantId = $user?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);
            if ($user && (bool) ($user->super_admin ?? false)) {
                $requestedTenantId = request()->input('tenant_id');
                $tenantId = $requestedTenantId ? (int) $requestedTenantId : $tenantId;
                if ($tenantId && !Tenant::withoutGlobalScopes()->whereKey($tenantId)->exists()) {
                    throw new LogicException('The selected tenant does not exist.');
                }
            }

            if ($user && $tenantId) {
                $model->tenant_id = $tenantId;
            } elseif ($user && !((bool) ($user->super_admin ?? false))) {
                throw new LogicException('Cannot create a tenant-owned record without a tenant.');
            } elseif ($user && (bool) ($user->super_admin ?? false)) {
                throw new LogicException('Super admin must select a tenant before creating this record.');
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
