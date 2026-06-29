<?php
namespace App\Http\Requests\Admin\AiTool;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateAiToolRequest extends FormRequest
{
    /**
    * Determine if the user is authorized to make this request.
    *
    * @return bool
    */
    public function authorize()
    {
        return Gate::allows("crafter.ai-tool.edit");
    }

    /**
    * Get the validation rules that apply to the request.
    *
    * @return array
    */
    public function rules()
    {
        return [
            'name' => ['sometimes'],
            'description' => ['sometimes'],
            'provider' => ['sometimes','string'],
            'config' => ['sometimes'],
            'enabled' => ['sometimes','boolean'],
            'order' => ['sometimes'],
        ];
    }
}
