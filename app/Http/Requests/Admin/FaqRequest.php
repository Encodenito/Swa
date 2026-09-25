<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:2000'],
            'keywords' => ['required', 'string', 'max:1000'],
            'active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
