<?php

namespace App\Http\Requests\Crm;

use App\Enums\Role;
use App\Support\SensitiveData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFollowUpRequest extends FormRequest
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
        $customer = $this->route('customer');

        return [
            'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before:+1 year'],
            'note' => ['required', 'string', 'min:2', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && SensitiveData::containsCardNumber($value)) {
                    $fail('This looks like a card number. Never store card numbers in the app.');
                }
            }],
            // Only a reservation belonging to this customer can be linked.
            'reservation_id' => ['nullable', 'integer', Rule::exists('reservations', 'id')->where('user_id', $customer?->id)],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->whereIn('role', [Role::Staff->value, Role::Admin->value])->whereNull('deactivated_at')],
        ];
    }
}
