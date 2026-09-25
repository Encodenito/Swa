<?php

namespace App\Http\Requests\Crm;

use App\Support\SensitiveData;
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
            'body' => ['required', 'string', 'min:2', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && SensitiveData::containsCardNumber($value)) {
                    $fail('This looks like a card number. Never store card numbers in the app.');
                }
            }],
        ];
    }
}
