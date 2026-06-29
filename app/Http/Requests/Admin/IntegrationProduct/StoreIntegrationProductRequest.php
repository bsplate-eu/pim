<?php
namespace App\Http\Requests\Admin\IntegrationProduct;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreIntegrationProductRequest extends FormRequest
{
    /**
    * Determine if the user is authorized to make this request.
    *
    * @return bool
    */
    public function authorize()
    {
        return Gate::allows("crafter.integration-product.create");
    }

    /**
    * Get the validation rules that apply to the request.
    *
    * @return array
    */
    public function rules()
    {
        return [
            'integration_id' => ['required','exists:integrations,id'],
            'product_id' => ['required','exists:products,id'],
            'overrides' => ['nullable'],
            'synced_at' => ['nullable'],
        ];
    }
}
