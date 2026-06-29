<?php
namespace App\Http\Requests\Admin\IntegrationProduct;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateIntegrationProductRequest extends FormRequest
{
    /**
    * Determine if the user is authorized to make this request.
    *
    * @return bool
    */
    public function authorize()
    {
        return Gate::allows("crafter.integration-product.edit");
    }

    /**
    * Get the validation rules that apply to the request.
    *
    * @return array
    */
    public function rules()
    {
        return [
            'integration_id' => ['sometimes','exists:integrations,id'],
            'product_id' => ['sometimes','exists:products,id'],
            'overrides' => ['nullable'],
            'synced_at' => ['nullable'],
        ];
    }
}
