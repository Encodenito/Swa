<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The cart comes from the session, never from this request. Only the terms checkbox is posted.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms.accepted' => 'Please accept the reservation terms.',
        ];
    }
}
