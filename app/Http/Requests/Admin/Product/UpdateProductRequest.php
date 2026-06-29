<?php

namespace App\Http\Requests\Admin\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Gate::allows("crafter.product.edit");
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'source_id' => ['sometimes', 'numeric'],
            'external_id' => [
                'bail',
                'sometimes',
                Rule::unique('products')
                    ->where('source_id', $this->source_id)
                    ->ignore($this->product->id)
            ],
            'category' => ['sometimes', 'string'],
            'name' => ['sometimes'],
            'product_code' => ['sometimes', 'string'],
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
            'enabled' => ['sometimes', 'boolean'],
            'attribute_values' => ['sometimes','array'],
            'category_ids' => ['sometimes','array'],
        ];
    }
}
