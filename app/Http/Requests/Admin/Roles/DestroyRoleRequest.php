<?php

namespace App\Http\Requests\Admin\Roles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DestroyRoleRequest extends FormRequest
{
    public function authorize()
    {
        return Gate::allows('crafter.role.edit');
    }

    public function rules()
    {
        return [];
    }
}
