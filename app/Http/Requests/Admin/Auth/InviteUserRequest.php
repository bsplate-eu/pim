<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Zaproszenie zakłada konto i nadaje mu rolę — to to samo, co utworzenie
        // użytkownika. Bez tej bramki każdy zalogowany mógł zaprosić sam siebie
        // na Administratora.
        return Gate::allows('crafter.admin-user.create');
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'string', Rule::unique('admin_users', 'email')->whereNull('deleted_at')],
            // Formularz wysyła NAZWĘ roli (lista ról leci z Role::all()->pluck('name')),
            // a AdminUser::assignRole() przyjmuje nazwę — walidujemy po kolumnie name.
            'role_id' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'crafter')],
        ];
    }
}
