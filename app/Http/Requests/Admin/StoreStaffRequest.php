<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
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
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => preg_replace('/[\s-]+/', '', $this->input('phone')) ?: null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^(09|\+639)\d{9}$/'],
            'role' => ['required', Rule::in([Role::Staff->value, Role::Admin->value])],
            // Staff and admin accounts guard money and stock: longer temporary passwords, changed on first login.
            'password' => ['required', Password::min(12)->letters()->numbers()],
        ];
    }
}
