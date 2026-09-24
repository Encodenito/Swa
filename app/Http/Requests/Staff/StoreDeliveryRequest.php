<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryRequest extends FormRequest
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
        $money = ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/'];

        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'expected_at' => ['nullable', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'integer', 'distinct', 'exists:items,id'],
            'lines.*.quantity_ordered' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_cost' => $money,
            // Clearance prices are an admin decision; staff submissions are stripped below.
            'lines.*.sale_price' => $money,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        $data['lines'] = array_map(fn (array $line) => [
            'item_id' => (int) $line['item_id'],
            'quantity_ordered' => (int) $line['quantity_ordered'],
            'unit_cost' => $line['unit_cost'] ?? null,
            'sale_price' => $this->user()->isAdmin() ? ($line['sale_price'] ?? null) : null,
        ], $data['lines']);

        return data_get($data, $key, $default);
    }
}
