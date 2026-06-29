<?php
namespace App\Http\Requests\Admin\Integration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreIntegrationRequest extends FormRequest
{
    /**
    * Determine if the user is authorized to make this request.
    *
    * @return bool
    */
    public function authorize()
    {
        return Gate::allows("crafter.integration.create");
    }

    /**
    * Get the validation rules that apply to the request.
    *
    * @return array
    */
    public function rules()
    {
        $rules = [
            'category_id' => ['nullable','exists:categories,id'],
            'type' => ['required','string'],
            'name' => ['required','string'],
            'manufacturer' => ['required','string'],
            'url' => in_array($this->type, ['prestashop', 'litecart', 'opencart']) ? ['required', 'url:http,https'] : ['nullable'],
            'key' => in_array($this->type, ['prestashop', 'litecart', 'opencart']) ? ['required', 'string'] : ['nullable'],
            'integration_sources' => ['required','array', 'min:1'],
            'integration_sources.*.source_id' => ['required','exists:sources,id'],
            'integration_sources.*.template_id' => ['required','exists:templates,id'],
            'integration_sources.*.pricelist_id' => ['required','exists:pricelists,id'],
            'integration_sources.*.tax' => ['required', 'numeric'],
            'integration_sources.*.multiplier' => ['required', 'numeric'],
            'enabled' => ['sometimes','boolean'],
        ];

        return $rules;
    }
}
