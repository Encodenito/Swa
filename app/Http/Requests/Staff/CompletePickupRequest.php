<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class CompletePickupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The amount is checked against the server's balance in ReservationService::complete.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'id_checked' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter the amount in pesos, e.g. 1234.50.',
            'id_checked.accepted' => 'Confirm that you checked the customer is the account holder.',
        ];
    }
}
