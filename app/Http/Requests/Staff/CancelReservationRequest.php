<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Staff may only choose 0%; admins 0, 50 or 100% (spec B). The service re-checks.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowed = $this->user()->isAdmin() ? [0, 50, 100] : [0];

        return [
            'refund_percent' => ['required', 'integer', Rule::in($allowed)],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'refund_percent.in' => $this->user()->isAdmin()
                ? 'Choose a 0, 50 or 100% refund.'
                : 'Staff can only cancel without a refund. Ask an admin for a refund.',
        ];
    }
}
