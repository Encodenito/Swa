<?php

namespace App\Http\Requests\Staff;

use App\Models\Delivery;
use Illuminate\Foundation\Http\FormRequest;

class ReceiveDeliveryRequest extends FormRequest
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
            'received' => ['required', 'array'],
            'received.*' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * Only quantities for this delivery's own lines are kept.
     *
     * @return array<int, int>
     */
    public function receivedQuantities(Delivery $delivery): array
    {
        $lineIds = $delivery->lines()->pluck('id')->all();

        return collect($this->validated('received'))
            ->mapWithKeys(fn ($qty, $lineId) => [(int) $lineId => (int) $qty])
            ->only($lineIds)
            ->all();
    }
}
