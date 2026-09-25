<?php

namespace App\Http\Requests\Staff;

use App\Enums\Role;
use App\Enums\SettingKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerReservationRequest extends FormRequest
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
            'customer_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', Role::Customer->value)->whereNull('deactivated_at')->whereNotNull('email_verified_at')],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => ['required', 'integer', 'distinct', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.setting(SettingKey::MaxQtyPerItem)],
            'terms_confirmed' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.exists' => 'Choose an active customer account with a verified email.',
            'terms_confirmed.accepted' => 'Confirm that the customer agreed to the reservation terms.',
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
