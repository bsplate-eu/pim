<?php
namespace App\Http\Requests\Admin\Template;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateTemplateRequest extends FormRequest
{
    /**
    * Determine if the user is authorized to make this request.
    *
    * @return bool
    */
    public function authorize()
    {
        return Gate::allows("crafter.template.edit");
    }

    /**
    * Get the validation rules that apply to the request.
    *
    * @return array
    */
    public function rules()
    {
        return [
            'name' => ['sometimes','string'],
            'locale' => ['required','string'],
            'title' => ['sometimes'],
            'description' => ['sometimes'],
            'short_description' => ['sometimes'],
            'meta_title' => ['sometimes', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
