<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BatchPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Empty removes the clearance price.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sale_price' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ];
    }
}
