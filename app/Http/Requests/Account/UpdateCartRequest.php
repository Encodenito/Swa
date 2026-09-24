<?php

namespace App\Http\Requests\Account;

use App\Enums\SettingKey;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only an item id and a quantity are accepted; prices never come from the client.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $min = $this->routeIs('account.cart.update') ? 0 : 1;

        return [
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity' => ['required', 'integer', "min:{$min}", 'max:'.setting(SettingKey::MaxQtyPerItem)],
        ];
    }
}
