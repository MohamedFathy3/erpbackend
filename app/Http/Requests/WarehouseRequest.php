<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
   public function rules(): array
{
    $rules = [
        'name' => ['required', 'string', 'max:255'],
        'code' => ['nullable', 'string', 'max:100'],
        'address' => ['nullable', 'string'],
        'phone' => ['nullable', 'string'],
        'manager' => ['nullable', 'string'],
        'active' => ['boolean'],
        'main_branch' => ['boolean'],
        'note' => ['nullable', 'string'],
        'branch_id' => ['nullable', 'integer', 'exists:branches,id'], // ← مهم
    ];

    if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];
    }

    return $rules;
}
}


