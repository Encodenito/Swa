<?php

namespace App\Http\Requests\Staff;

use App\Enums\SettingKey;
use Illuminate\Foundation\Http\FormRequest;

class StoreWalkInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept 0917 123 4567 or 0917-123-4567: strip spaces and dashes before validating.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('walk_in_phone'))) {
            $this->merge(['walk_in_phone' => preg_replace('/[\s-]+/', '', $this->input('walk_in_phone')) ?: null]);
        }
    }

    /**
     * Item ids and quantities only; prices and the total come from the server.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => ['required', 'integer', 'distinct', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.max(100, setting(SettingKey::MaxQtyPerItem))],
            'walk_in_name' => ['nullable', 'string', 'max:150'],
            'walk_in_phone' => ['nullable', 'string', 'regex:/^(09|\+639)\d{9}$/'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function quantities(): array
    {
        return collect($this->validated('lines'))
            ->mapWithKeys(fn (array $line) => [(int) $line['item_id'] => (int) $line['quantity']])
            ->all();
    }
}
