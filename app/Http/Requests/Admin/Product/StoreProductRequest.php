<?php
namespace App\Http\Requests\Admin\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreProductRequest extends FormRequest
{
    /**
    * Determine if the user is authorized to make this request.
    *
    * @return bool
    */
    public function authorize()
    {
        return Gate::allows("crafter.product.create");
    }

    /**
    * Get the validation rules that apply to the request.
    *
    * @return array
    */
    public function rules()
    {
        return [
            'source_id' => ['required', 'numeric'],
            'external_id' => ['required', 'numeric', 'unique:products,external_id'],
            'category' => ['required','string'],
            'name' => ['required'],
            'product_code' => ['required','string'],
            'width' => ['nullable'],
            'weight' => ['nullable'],
            'info_1' => ['nullable'],
            'info_2' => ['nullable'],
            'info_3' => ['nullable'],
            'meta_url' => ['nullable'],
            'meta_title' => ['nullable'],
            'meta_description' => ['nullable'],
            'meta_keywords' => ['nullable'],
            'images' => ['nullable'],
            'enabled' => ['sometimes','boolean'],
            'attribute_values' => ['sometimes','array'],
            'category_ids' => ['sometimes','array'],
        ];
    }
}
