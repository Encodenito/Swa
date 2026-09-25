<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class StoreNoteRequest extends FormRequest
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
            // Spec K.12/K.14: never card data, keep personal data minimal.
            'body' => ['required', 'string', 'min:2', 'max:2000', 'not_regex:/(?:\d[ -]?){13,19}/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.not_regex' => 'This looks like a card or account number. Do not store card numbers or ID numbers in notes.',
        ];
    }
}
