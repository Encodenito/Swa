<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * Allowed range per setting.
     *
     * @var array<string, array{int, int}>
     */
    public const RANGES = [
        'downpayment_percent' => [1, 100],
        'pickup_days' => [1, 30],
        'forfeit_refund_percent' => [0, 100],
        'pending_minutes' => [10, 240],
        'max_unpaid_reservations' => [1, 10],
        'max_qty_per_item' => [1, 100],
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (self::RANGES as $key => [$min, $max]) {
            $rules[$key] = ['required', 'integer', "between:{$min},{$max}"];
        }

        return $rules;
    }
}
