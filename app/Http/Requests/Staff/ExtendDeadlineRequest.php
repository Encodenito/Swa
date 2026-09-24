<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class ExtendDeadlineRequest extends FormRequest
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
            'pickup_by' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before:+60 days'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
