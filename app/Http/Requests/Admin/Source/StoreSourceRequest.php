<?php
namespace App\Http\Requests\Admin\Source;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSourceRequest extends FormRequest
{
    /**
    * Determine if the user is authorized to make this request.
    *
    * @return bool
    */
    public function authorize()
    {
        return Gate::allows("crafter.source.create");
    }

    /**
    * Get the validation rules that apply to the request.
    *
    * @return array
    */
    public function rules()
    {
        return [
            'name' => ['required','string'],
            'service_class' => ['nullable','string'],
            'options' => ['nullable'],
            'enabled' => ['required','boolean'],
        ];
    }
}
