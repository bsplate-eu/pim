<?php
namespace App\Http\Requests\Admin\Pricelist;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdatePricelistRequest extends FormRequest
{
    /**
    * Determine if the user is authorized to make this request.
    *
    * @return bool
    */
    public function authorize()
    {
        return Gate::allows("crafter.pricelist.edit");
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
            'currency' => ['sometimes','string'],
            'price_formula' => ['sometimes','nullable','string','max:255'],
            'price_formula_mode' => ['sometimes','nullable','string','in:percent,multiply'],
            'rows' => ['sometimes','array'],
            'rows.*.product_id' => ['required','integer'],
            'rows.*.price' => ['nullable'],
            'rows.*.auto_price' => ['nullable'],
            'rows.*.manual_price' => ['nullable'],
        ];
    }
}
