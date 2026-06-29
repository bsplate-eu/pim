<?php
namespace App\Http\Requests\Admin\Integration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateIntegrationRequest extends FormRequest
{
    /**
    * Determine if the user is authorized to make this request.
    *
    * @return bool
    */
    public function authorize()
    {
        return Gate::allows("crafter.integration.edit");
    }

    /**
    * Get the validation rules that apply to the request.
    *
    * @return array
    */
    public function rules()
    {
        return [
            'category_id' => ['nullable','exists:categories,id'],
            'type' => ['sometimes','string'],
            'name' => ['sometimes','string'],
            'manufacturer' => ['sometimes','string'],
            'url' => in_array($this->type, ['prestashop', 'litecart', 'opencart']) ? ['sometimes', 'url:http,https'] : ['nullable'],
            'key' => in_array($this->type, ['prestashop', 'litecart', 'opencart']) ? ['sometimes', 'string'] : ['nullable'],
            'integration_sources' => ['sometimes','array', 'min:1'],
            'integration_sources.*.source_id' => ['sometimes','exists:sources,id'],
            'integration_sources.*.template_id' => ['sometimes','exists:templates,id'],
            'integration_sources.*.pricelist_id' => ['sometimes','exists:pricelists,id'],
            'integration_sources.*.tax' => ['sometimes', 'numeric'],
            'integration_sources.*.multiplier' => ['sometimes', 'numeric'],
            'enabled' => ['sometimes','boolean'],
        ];
    }
}
