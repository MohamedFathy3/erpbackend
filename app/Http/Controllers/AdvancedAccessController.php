<?php
namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdvancedAccessController extends Controller
{
    public function permissions(Request $request) { abort_unless($request->user()?->hasPermission('roles.manage'), 403); $column = Permission::identifierColumn(); $query = Permission::query()->orderBy($column); if (Schema::hasColumn('permissions', 'module')) $query->orderBy('module'); return response()->json(['data'=>$query->get()]); }
    public function roles(Request $request) { abort_unless($request->user()?->hasPermission('roles.manage'), 403); return response()->json(['data'=>Role::with('permissions')->orderBy('name')->get()]); }
    public function updateRole(Request $request, Role $role) { abort_unless($request->user()?->hasPermission('roles.manage'), 403); $data=$request->validate(['name'=>'required|string|max:100','permissions'=>'array','permissions.*'=>'integer|exists:permissions,id']); $role->update(['name'=>$data['name']]); $role->permissions()->sync($data['permissions'] ?? []); return response()->json(['data'=>$role->fresh('permissions')]); }
    public function mePermissions(Request $request) { $user=$request->user(); abort_unless($user, 401); return response()->json(['data'=>['is_super_admin'=>(bool)($user->super_admin ?? false),'permissions'=>$user->permissionKeys()]]); }
}
