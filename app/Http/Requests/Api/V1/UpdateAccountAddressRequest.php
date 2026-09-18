<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AccountMaster;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $accountId = (int) $this->route('account');
        $account = AccountMaster::query()->find($accountId);

        if (! $account) {
            return false;
        }

        return $this->user()?->can('update', $account) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type_master_id' => ['sometimes', 'integer', 'exists:type_masters,id'],
            'street' => ['sometimes', 'string', 'max:255'],
            'pin_code' => ['nullable', 'string', 'max:20'],
            'area_town_id' => ['sometimes', 'integer', 'exists:city_pin_codes,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'is_primary' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
