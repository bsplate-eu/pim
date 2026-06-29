<?php

namespace App\Http\Requests\Admin\AdminUser;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ImpersonalLoginAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('crafter.admin-user.impersonal-login', $this->adminUser);
    }

    public function rules(): array
    {
        return [];
    }
}
