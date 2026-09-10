<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Interfaces\RoleRepositoryInterface;
use App\Models\Role;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends BaseController
{
    protected mixed $crudRepository;

    public function __construct(RoleRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    public function index(Request $request)
    {
        try {
            $query = Role::query()->with('permissions');
            if ($request->filled('search')) {
                $query->where('name', 'like', '%' . $request->input('search') . '%');
            }
            return JsonResponse::respondSuccess('Items Fetched Successfully', $query->latest('id')->get());
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        return DB::transaction(function () use ($data) {
            $role = Role::create(['name' => $data['name']]);
            $role->permissions()->sync($data['permission_ids'] ?? []);
            return JsonResponse::respondSuccess('Role created successfully', $role->load('permissions'));
        });
    }

    public function permissions()
    {
        return JsonResponse::respondSuccess('Permissions Fetched Successfully', \App\Models\Permission::query()->orderBy('name')->get());
    }

    public function show(Role $role)
    {
        return JsonResponse::respondSuccess('Role Fetched Successfully', $role->load('permissions'));
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', 'unique:roles,name,' . $role->id],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        return DB::transaction(function () use ($data, $role) {
            $role->update(array_filter(['name' => $data['name'] ?? null], fn ($value) => $value !== null));
            if (array_key_exists('permission_ids', $data)) {
                $role->permissions()->sync($data['permission_ids']);
            }
            return JsonResponse::respondSuccess('Role updated successfully', $role->load('permissions'));
        });
    }

    public function destroy(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecords('roles', $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function restore(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->restoreItem(Role::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_RESTORED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function forceDelete(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecordsFinial(Role::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_FORCE_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }
}
