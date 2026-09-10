<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use App\Models\Tenant;
use LogicException;

/**
 * Applies tenant isolation to legacy models that intentionally keep their
 * original Model base class (and therefore do not use BaseModel's soft deletes).
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $model = $builder->getModel();
            if (!Schema::hasColumn($model->getTable(), 'tenant_id')) return;

            $user = auth()->user();
            if (!$user || (bool) ($user->super_admin ?? false)) return;

            $tenantId = $user->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);
            $tenantId
                ? $builder->where($model->qualifyColumn('tenant_id'), $tenantId)
                : $builder->whereRaw('1 = 0');
        });

        static::creating(function (Model $model): void {
            if (!Schema::hasColumn($model->getTable(), 'tenant_id') || $model->tenant_id) return;

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
}
