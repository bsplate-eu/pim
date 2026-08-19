<?php

namespace App\Http\Requests\Admin\Permissions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdatePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Gate::allows('crafter.permission.edit');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'roles' => ['required', 'array'],
            // Poprzednio: 'roles.permissions.*' — ścieżka nigdy nie istniała, więc
            // nic nie było walidowane. Payload to lista ról, każda z tablicą nazw.
            'roles.*.id' => ['required', 'integer', 'exists:roles,id'],
            'roles.*.permissions' => ['present', 'array'],
            'roles.*.permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        return $validated;
    }
}
