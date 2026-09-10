<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\AdminRequest;
use App\Http\Resources\AdminResource;
use App\Http\Resources\EmployeeResource;
use App\Interfaces\AdminRepositoryInterface;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TenantModule;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminController extends BaseController
{
    protected mixed $crudRepository;

    public function __construct(AdminRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    public function index()
    {
        try {
            $admin = AdminResource::collection($this->crudRepository->all(
                [],
                [],
                ['*']
            ));
            return $admin->additional(JsonResponse::success());
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function store(AdminRequest $request)
    {
        try {
            $data = $request->validated();
            $actor = $request->user();

            if ($actor && !($actor->super_admin ?? false)) {
                $data['tenant_id'] = $actor->tenant_id;
            } elseif (!$actor) {
                // The public registration endpoint is a workspace signup, not a
                // way to create an unscoped admin in the shared database.
                $tenant = DB::transaction(function () use ($data): Tenant {
                    $baseSlug = Str::slug($data['name'] ?? Str::before($data['email'], '@')) ?: 'workspace';
                    $slug = $baseSlug;
                    $suffix = 1;
                    while (Tenant::withoutGlobalScopes()->where('slug', $slug)->exists()) {
                        $slug = $baseSlug . '-' . (++$suffix);
                    }
                    $tenant = Tenant::withoutGlobalScopes()->create([
                        'name' => $data['name'] ?? $slug,
                        'slug' => $slug,
                        'status' => 'trial',
                        'plan' => 'trial',
                        'trial_starts_at' => now(),
                        'trial_ends_at' => now()->addDays(15),
                        'subscription_status' => 'trial',
                    ]);
                    foreach (TenantModule::available() as $moduleKey) {
                        TenantModule::withoutGlobalScopes()->create([
                            'tenant_id' => $tenant->id,
                            'module_key' => $moduleKey,
                            'is_enabled' => true,
                        ]);
                    }
                    return $tenant;
                });
                $data['tenant_id'] = $tenant->id;
                $data['super_admin'] = false;
            }

            $admin = $this->crudRepository->create($data);
            if (request('logo') !== null) {
                $this->crudRepository->AddMediaCollection('logo', $admin,'logo');
            }
            return new AdminResource($admin);
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function show(Admin $admin): ?\Illuminate\Http\JsonResponse
    {
        try {
            return JsonResponse::respondSuccess('Item Fetched Successfully', new AdminResource($admin));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function update(AdminRequest $request, Admin $admin)
    {
        try {
            $this->crudRepository->update($request->validated(), $admin->id);
            if (request('logo') !== null) {
                $network = Admin::find($admin->id);
                $this->crudRepository->AddMediaCollection('logo', $network, 'logo');
            }
             if (request('logo_icon') !== null) {
                $network = Admin::find($admin->id);
                $this->crudRepository->AddMediaCollection('logo_icon', $network, 'logo_icon');
            }
            activity()->performedOn($admin)->withProperties(['attributes' => $admin])->log('update');
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_UPDATED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function destroy(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecords('admins', $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function restore(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->restoreItem(Admin::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_RESTORED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }




    public function forceDelete(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecordsFinial(Admin::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_FORCE_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

   public function login(Request $request): \Illuminate\Http\JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['nullable', 'email'],
            'identifier' => ['nullable', 'string'],
            'password' => ['required', 'string'],
        ]);
        $credentials['email'] = $credentials['email'] ?? $credentials['identifier'] ?? null;
        if (!$credentials['email']) {
            return response()->json(['message' => 'Email is required'], 422);
        }

        // 🔹 محاولة تسجيل الدخول كـ Admin
        $admin = Admin::where('email', $credentials['email'])->first();

        if ($admin) {
            // تحديث الـ hash إذا لازم
            if ($admin->password && Hash::needsRehash($admin->password)) {
                $admin->password = Hash::make($credentials['password']);
                $admin->save();
            }

            if ($admin->password && Hash::check($credentials['password'], $admin->password)) {
                activity()->performedOn($admin)->withProperties(['attributes' => $admin])->log('login');

                $token = $admin->createToken('admin-token')->plainTextToken;

                return response()->json([
                    'type' => 'admin',
                    'data' => new AdminResource($admin),
                    'token' => $token,
                ]);
            }
        }

        // 🔹 محاولة تسجيل الدخول كـ Employee
        $employee = Employee::where('email', $credentials['email'])->first();

        if ($employee && Hash::check($credentials['password'], $employee->password)) {
            $token = $employee->createToken('employee-token')->plainTextToken;

            return response()->json([
                'type' => 'employee',
                'data' => new EmployeeResource($employee),
                'token' => $token,
            ]);
        }

        // 🔹 إذا لا Admin ولا Employee
        return response()->json([
            'result' => 'Error',
            'message' => 'Invalid credentials',
        ], 401);
    }

    public function logout()
    {
        try {
            auth('admins')->user()->tokens()->delete();
            return response()->json(['message' => 'Successfully logged out']);
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage(), 401);
        }
    }

    public function getCurrentAdmin()
    {
        try {

           $user = auth()->user();

        if ($user instanceof \App\Models\Admin) {
            return response()->json([
                'type' => 'admin',
                'data' => new AdminResource($user)
            ]);
        }

        if ($user instanceof \App\Models\Employee) {
            return response()->json([
                'type' => 'employee',
                'data' => new EmployeeResource($user)
            ]);
        }


            return response()->json([
                'status' => false,
                'message' => 'غير مصرح'
            ], 401);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /////////////////////////////// testing activity log ///////////////////////////////
}
