<?php

namespace App\Http\Requests\Admin\Roles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize()
    {
        return Gate::allows('crafter.role.edit');
    }

    public function rules()
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->where('guard_name', 'crafter'),
            ],
            // Opcjonalnie: skopiuj uprawnienia z istniejącej roli (wygodny start).
            'copy_from_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
        ];
    }

    public function attributes()
    {
        return [
            'name' => 'nazwa roli',
        ];
    }
}
