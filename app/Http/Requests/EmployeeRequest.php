<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('employee') ? $this->route('employee')->id : null;

        return [
            'employee_code' => 'required|string|max:100|unique:employees,employee_code,' . $id,
            'name'          => 'required|string|max:255',
            'position'      => 'nullable|string|max:255',
            'department'    => 'nullable|string|max:255',
            // A user may intentionally have no role and receive only direct
            // permissions. Keep the tenant restriction when a role is given.
            'role_id'       => ['nullable', Rule::exists('roles', 'id')->where(function ($query): void {
                $tenantId = auth()->user()?->tenant_id ?: (app()->bound('currentTenantId') ? app('currentTenantId') : null);
                if ($tenantId) $query->where('tenant_id', $tenantId);
            })],
            'permissions'   => 'nullable|array',
            'permissions.*' => 'integer|exists:permissions,id',
            'branch_id'     => 'required|exists:branches,id', // ✅ إضافة
            'treasury_id'   => 'required|exists:treasuries,id', // ✅ إضافة
            'phone'         => 'nullable|string|max:20',
            'email'         => 'nullable|email|unique:employees,email,' . $id,
            'salary'        => 'nullable|numeric|min:0',
            'password'      => $this->isMethod('post') ? 'required|string|min:6' : 'nullable|string|min:6',
        ];
    }

    public function messages(): array
    {
        return [
            'employee_code.required' => 'كود الموظف مطلوب',
            'employee_code.unique'   => 'كود الموظف مستخدم بالفعل',
            'role_id.exists'         => 'الصلاحية غير موجودة',
            'branch_id.exists'       => 'الفرع غير موجود', // ✅ إضافة
            'treasury_id.exists'     => 'الخزينة غير موجودة', // ✅ إضافة
            'email.unique'           => 'البريد الإلكتروني مستخدم بالفعل',
            'salary.numeric'         => 'الراتب يجب أن يكون رقمًا',
            'password.min'           => 'كلمة المرور يجب أن تكون على الأقل 6 أحرف',
        ];
    }
}
