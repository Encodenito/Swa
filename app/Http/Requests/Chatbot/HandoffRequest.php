<?php

namespace App\Http\Requests\Chatbot;

use Illuminate\Foundation\Http\FormRequest;

class HandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('contact')) && ! str_contains($this->input('contact'), '@')) {
            $this->merge(['contact' => preg_replace('/[\s-]+/', '', $this->input('contact'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // An email address or a Philippine mobile number, so staff can reply.
            'contact' => ['required', 'string', 'max:150', function (string $attribute, mixed $value, \Closure $fail) {
                $ok = filter_var($value, FILTER_VALIDATE_EMAIL) || preg_match('/^(09|\+639)\d{9}$/', (string) $value);
                if (! $ok) {
                    $fail('Enter an email address or a mobile number like 09171234567.');
                }
            }],
            'message' => ['required', 'string', 'min:2', 'max:1000'],
            'transcript' => ['nullable', 'array', 'max:10'],
            'transcript.*.from' => ['required', 'in:user,bot'],
            'transcript.*.text' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return list<array{from: string, text: string}>
     */
    public function transcript(): array
    {
        return collect($this->validated('transcript') ?? [])
            ->take(-10)
            ->map(fn (array $turn) => ['from' => $turn['from'], 'text' => mb_substr($turn['text'], 0, 1000)])
            ->values()
            ->all();
    }
}
