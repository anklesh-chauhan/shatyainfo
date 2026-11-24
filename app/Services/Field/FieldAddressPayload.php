<?php

namespace App\Services\Field;

use App\Models\Address;
use App\Models\CityPinCode;
use App\Models\TypeMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

class FieldAddressPayload
{
    public const RELATIONS = ['typeMaster', 'areaTown', 'city', 'state', 'country'];

    public function change(User $actor, MorphMany $addresses, ?int $id, ?array $data, ?string $addressType = null): ?Address
    {
        $address = $id === null ? $addresses->make() : $addresses->with('approval')->lockForUpdate()->findOrFail($id);
        $this->assertEditable($actor, $address);
        if ($data === null) {
            abort_unless($address->delete(), 409);

            return null;
        }

        $address->fill($this->normalize($data, $address->exists ? $address : null, allowUnmapped: true, addressType: $addressType));
        $address->save();

        return $address->load(self::RELATIONS);
    }

    public function assertEditable(User $actor, Address $address): void
    {
        $states = [$address->approval_status, $address->exists ? $address->approval?->approval_status : null];
        abort_if(in_array('pending', $states, true) || (in_array('approved', $states, true) && $actor->cannot('OverrideApproval:Address')), 422, 'This address is locked by approval.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data, ?Address $existing = null, bool $allowUnmapped = false, ?string $addressType = null): array
    {
        if (isset($data['type_master_id'])) {
            $this->assertAddressTypeMaster((int) $data['type_master_id']);
        } elseif ($existing === null && $addressType === null) {
            throw ValidationException::withMessages([
                'type_master_id' => 'Address type is required.',
            ]);
        }

        $areaTownId = $allowUnmapped && array_key_exists('area_town_id', $data) ? $data['area_town_id'] : ($data['area_town_id'] ?? $existing?->area_town_id);
        $pinCode = $data['pin_code'] ?? $existing?->pin_code;

        if ($areaTownId === null && ! $allowUnmapped) {
            throw ValidationException::withMessages([
                'area_town_id' => 'Area/town is required.',
            ]);
        }

        $areaTown = $areaTownId === null ? null : CityPinCode::query()->find($areaTownId);

        if ($areaTownId !== null && ! $areaTown) {
            throw ValidationException::withMessages([
                'area_town_id' => 'Invalid area/town.',
            ]);
        }

        if ($areaTown === null && blank($pinCode)) {
            throw ValidationException::withMessages(['pin_code' => 'Enter a pin code.']);
        }

        if ($areaTown && filled($pinCode) && (string) $areaTown->pin_code !== (string) $pinCode) {
            throw ValidationException::withMessages([
                'area_town_id' => 'Area/town does not match the pin code.',
            ]);
        }

        $street = $data['street'] ?? $existing?->street;
        if (blank($street)) {
            throw ValidationException::withMessages([
                'street' => 'Street is required.',
            ]);
        }

        return [
            ...($addressType === null ? [] : ['address_type' => $addressType]),
            'type_master_id' => $data['type_master_id'] ?? $existing?->type_master_id,
            'street' => $street,
            'pin_code' => $pinCode ?? $areaTown?->pin_code,
            'area_town_id' => $areaTown?->id,
            'city_id' => $data['city_id'] ?? ($areaTown ? $areaTown->city_id : $existing?->city_id),
            'state_id' => $data['state_id'] ?? ($areaTown ? $areaTown->state_id : $existing?->state_id),
            'country_id' => $data['country_id'] ?? ($areaTown ? $areaTown->country_id : $existing?->country_id),
            'is_primary' => (bool) ($data['is_primary'] ?? $existing?->is_primary ?? false),
            'sort' => $data['sort'] ?? $existing?->sort ?? 1,
        ];
    }

    private function assertAddressTypeMaster(int $typeMasterId): void
    {
        $exists = TypeMaster::query()
            ->where('typeable_type', Address::class)
            ->whereKey($typeMasterId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'type_master_id' => 'Invalid address type.',
            ]);
        }
    }
}
