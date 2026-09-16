<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class ColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $colorId = $this->route('color')?->id;

        $user = $this->user();

        $tenantId = $user?->tenant_id
            ?: (app()->bound('currentTenantId')
                ? app('currentTenantId')
                : null);

        if (!$tenantId) {
            throw new LogicException(
                'Cannot validate color without a tenant.'
            );
        }

        return [
            'name' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('colors', 'name')
                    ->where(fn ($query) =>
                        $query->where('tenant_id', $tenantId)
                    )
                    ->ignore($colorId),
            ],

            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('colors', 'code')
                    ->where(fn ($query) =>
                        $query->where('tenant_id', $tenantId)
                    )
                    ->ignore($colorId),
            ],

            'hex_code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('colors', 'hex_code')
                    ->where(fn ($query) =>
                        $query->where('tenant_id', $tenantId)
                    )
                    ->ignore($colorId),
            ],
        ];
    }
}