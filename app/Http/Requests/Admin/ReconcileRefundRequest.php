<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReconcileRefundRequest extends FormRequest
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
            'outcome' => ['required', Rule::in(['found', 'not_found'])],
            'paymongo_refund_id' => ['required_if:outcome,found', 'nullable', 'string', 'regex:/^ref_[A-Za-z0-9]+$/', 'max:100', 'unique:payments,provider_ref', 'unique:orphan_payments,refund_ref'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paymongo_refund_id.required_if' => 'Enter the refund id shown in the PayMongo dashboard (starts with ref_).',
            'paymongo_refund_id.regex' => 'PayMongo refund ids start with ref_.',
        ];
    }
}
