<?php

namespace App\Http\Requests\Admin;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemRequest extends FormRequest
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
        /** @var Item|null $item */
        $item = $this->route('item');

        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:200'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('items', 'sku')->ignore($item)],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:1000'],
            'active' => ['boolean'],
            'superseded_by_id' => ['nullable', 'integer', 'exists:items,id', Rule::notIn(array_filter([$item?->id]))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.regex' => 'Enter the price in pesos, e.g. 1234.50.',
            'price.not_regex' => 'The price must be more than zero.',
            'superseded_by_id.not_in' => 'An item cannot replace itself.',
        ];
    }
}
