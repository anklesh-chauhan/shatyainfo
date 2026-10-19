<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\CityPinCode;
use App\Models\Company;
use App\Models\ContactDetail;
use App\Models\TypeMaster;
use App\Models\User;
use App\Services\TerritoryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldAccountService
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function syncReporting(AccountMaster $account, array $data): void
    {
        foreach (['division_profiles' => 'divisionProfiles', 'call_targets' => 'callTargets'] as $field => $relation) {
            foreach ($data[$field] ?? [] as $values) {
                $id = $values['id'] ?? null;
                unset($values['id']);
                if ($id) {
                    $record = $account->$relation()->find($id);
                    if (! $record) {
                        throw ValidationException::withMessages([$field => 'The reporting record does not belong to this account.']);
                    }
                    $record->update($values);
                } else {
                    $account->$relation()->create($values);
                }
            }
        }
    }

    /** @return list<string> */
    private function detailRelations(): array
    {
        return [
            'typeMaster',
            'specialty', 'therapyArea', 'divisionProfiles.ratingType', 'divisionProfiles.potentialUnit', 'callTargets', 'assignmentHistory',
            'territory',
            'contactDetails',
            'patches',
            'addresses.typeMaster',
            'addresses.areaTown',
            'addresses.city',
            'addresses.state',
            'addresses.country',
            'gstDetail',
            'creditDetail',
            'statutoryDetail',
            'bankDetail',
        ];
    }

    /**
     * Same visibility rules as Filament AccountMasterResource.
     *
     * @return LengthAwarePaginator<int, AccountMaster>
     */
    public function list(
        User $user,
        ?string $search = null,
        ?int $typeMasterId = null,
        ?int $territoryId = null,
        ?int $patchId = null,
        int $perPage = 50,
    ): LengthAwarePaginator {
        abort_unless($user->can('viewAny', AccountMaster::class), 403);

        $query = AccountMaster::query()
            ->with(['typeMaster', 'territory', 'contactDetails'])
            ->applyVisibility('AccountMaster')
            ->orderBy('name');

        if (filled($search)) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('account_code', 'like', '%'.$search.'%')
                    ->orWhere('phone_number', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('alias', 'like', '%'.$search.'%');
            });
        }

        if ($typeMasterId !== null) {
            $query->where('type_master_id', $typeMasterId);
        }

        if ($territoryId !== null) {
            $query->where('territory_id', $territoryId);
        }

        if ($patchId !== null) {
            $query->whereHas('patches', fn ($builder) => $builder->where('patches.id', $patchId));
        }

        return $query->paginate(max(1, min($perPage, 100)));
    }

    public function findVisible(User $user, int $accountId): AccountMaster
    {
        abort_unless($user->can('viewAny', AccountMaster::class), 403);

        $account = AccountMaster::query()
            ->with($this->detailRelations())
            ->applyVisibility('AccountMaster')
            ->find($accountId);

        if (! $account) {
            abort(404, 'Account not found.');
        }

        abort_unless($user->can('view', $account), 403);

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(User $user, array $data): AccountMaster
    {
        abort_unless($user->can('create', AccountMaster::class), 403);

        $this->assertTypeMaster((int) $data['type_master_id']);

        return DB::transaction(function () use ($user, $data): AccountMaster {
            $account = AccountMaster::query()->create([
                'name' => $data['name'],
                'type_master_id' => $data['type_master_id'],
                'owner_id' => $user->id,
                'phone_number' => $data['phone_number'] ?? null,
                'email' => $data['email'] ?? null,
                'secondary_email' => $data['secondary_email'] ?? null,
                'website' => $data['website'] ?? null,
                'no_of_employees' => $data['no_of_employees'] ?? null,
                'twitter' => $data['twitter'] ?? null,
                'linked_in' => $data['linked_in'] ?? null,
                'annual_revenue' => $data['annual_revenue'] ?? null,
                'sic_code' => $data['sic_code'] ?? null,
                'ticker_symbol' => $data['ticker_symbol'] ?? null,
                'alias' => $data['alias'] ?? null,
                'territory_id' => $data['territory_id'] ?? null,
                'industry_type_id' => $data['industry_type_id'] ?? null,
                'rating_type_id' => $data['rating_type_id'] ?? null,
                'account_status_id' => null,
                'specialty_id' => $data['specialty_id'] ?? null,
                'therapy_area_id' => $data['therapy_area_id'] ?? null,
                'institution_account_id' => $data['institution_account_id'] ?? null,
                'supplier_account_id' => $data['supplier_account_id'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'ref_dealer_contact' => $data['ref_dealer_contact'] ?? null,
                'commission' => $data['commission'] ?? null,
                'description' => $data['description'] ?? null,
                'approval_status' => 'draft',
            ]);

            if (! empty($data['patch_ids'])) {
                $account->patches()->sync($data['patch_ids']);
            }

            if (! empty($data['contact'])) {
                $this->attachContact($account, $data['contact']);
            }

            $addresses = $data['addresses'] ?? [];
            if (! empty($data['address'])) {
                $addresses[] = $data['address'];
            }

            foreach ($addresses as $addressData) {
                $this->createAddressForAccount($account, $addressData);
            }

            $this->syncRelatedDetails($account, $data);
            $this->syncReporting($account, $data);
            if (array_key_exists('account_status_id', $data)) {
                $account->update(['account_status_id' => $data['account_status_id']]);
            }

            return $account->fresh($this->detailRelations());
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, AccountMaster $account, array $data): AccountMaster
    {
        abort_unless($user->can('update', $account), 403);

        if (isset($data['type_master_id'])) {
            $this->assertTypeMaster((int) $data['type_master_id']);
        }

        return DB::transaction(function () use ($account, $data): AccountMaster {
            $account->fill(collect($data)->only([
                'name',
                'type_master_id',
                'phone_number',
                'email',
                'secondary_email',
                'website',
                'no_of_employees',
                'twitter',
                'linked_in',
                'annual_revenue',
                'sic_code',
                'ticker_symbol',
                'alias',
                'territory_id',
                'industry_type_id',
                'rating_type_id',
                'specialty_id', 'therapy_area_id', 'institution_account_id', 'supplier_account_id',
                'parent_id',
                'ref_dealer_contact',
                'commission',
                'description',
            ])->all())->save();

            if (array_key_exists('patch_ids', $data)) {
                $account->patches()->sync($data['patch_ids'] ?? []);
            }

            $this->syncRelatedDetails($account, $data);
            $this->syncReporting($account, $data);
            if (array_key_exists('account_status_id', $data)) {
                $account->update(['account_status_id' => $data['account_status_id']]);
            }

            return $account->fresh($this->detailRelations());
        });
    }

    /**
     * @return Collection<int, TypeMaster>
     */
    public function types(): Collection
    {
        return TypeMaster::query()
            ->ofType(AccountMaster::class)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, TypeMaster>
     */
    public function addressTypes(): Collection
    {
        return TypeMaster::query()
            ->where('typeable_type', Address::class)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, CityPinCode>
     */
    public function lookupPinCode(string $pinCode): Collection
    {
        return CityPinCode::query()
            ->with(['city', 'state', 'country'])
            ->where('pin_code', $pinCode)
            ->orderBy('area_town')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Address>|Collection<int, Address>
     */
    public function listAddresses(User $user, AccountMaster $account): Collection
    {
        abort_unless($user->can('view', $account), 403);

        return $account->addresses()
            ->with(['typeMaster', 'areaTown', 'city', 'state', 'country'])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    public function findAddress(User $user, AccountMaster $account, int $addressId): Address
    {
        abort_unless($user->can('view', $account), 403);

        $address = $account->addresses()
            ->with(['typeMaster', 'areaTown', 'city', 'state', 'country'])
            ->find($addressId);

        if (! $address) {
            abort(404, 'Address not found.');
        }

        return $address;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeAddress(User $user, AccountMaster $account, array $data): Address
    {
        abort_unless($user->can('update', $account), 403);

        return DB::transaction(fn (): Address => $this->createAddressForAccount($account, $data)
            ->load(['typeMaster', 'areaTown', 'city', 'state', 'country']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAddress(User $user, AccountMaster $account, Address $address, array $data): Address
    {
        abort_unless($user->can('update', $account), 403);
        $this->assertAddressBelongsToAccount($account, $address);

        return DB::transaction(function () use ($account, $address, $data): Address {
            $payload = $this->normalizeAddressPayload($data, existing: $address);
            $address->fill($payload)->save();
            $this->afterAddressSaved($account, $address);

            return $address->fresh(['typeMaster', 'areaTown', 'city', 'state', 'country']);
        });
    }

    public function deleteAddress(User $user, AccountMaster $account, Address $address): void
    {
        abort_unless($user->can('update', $account), 403);
        $this->assertAddressBelongsToAccount($account, $address);

        $address->delete();
    }

    private function assertTypeMaster(int $typeMasterId): void
    {
        $exists = TypeMaster::query()
            ->ofType(AccountMaster::class)
            ->whereKey($typeMasterId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'type_master_id' => 'Invalid account type.',
            ]);
        }
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

    private function assertAddressBelongsToAccount(AccountMaster $account, Address $address): void
    {
        if (
            (int) $address->addressable_id !== (int) $account->id
            || $address->addressable_type !== AccountMaster::class
        ) {
            abort(404, 'Address not found.');
        }
    }

    /**
     * @param  array{first_name?: string, last_name?: string|null, mobile_number?: string|null, email?: string|null, salutation?: string|null}  $contactData
     */
    private function attachContact(AccountMaster $account, array $contactData): void
    {
        if (blank($contactData['first_name'] ?? null) && blank($contactData['mobile_number'] ?? null)) {
            return;
        }

        $contact = ContactDetail::query()->create([
            'salutation' => $contactData['salutation'] ?? null,
            'first_name' => $contactData['first_name'] ?? $account->name,
            'last_name' => $contactData['last_name'] ?? null,
            'mobile_number' => $contactData['mobile_number'] ?? $account->phone_number,
            'whatsapp_number' => $contactData['mobile_number'] ?? $account->phone_number,
            'email' => $contactData['email'] ?? $account->email,
        ]);

        $account->contactDetails()->attach($contact->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createAddressForAccount(AccountMaster $account, array $data): Address
    {
        $payload = $this->normalizeAddressPayload($data);

        $address = $account->addresses()->create($payload);
        $this->afterAddressSaved($account, $address);

        return $address;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeAddressPayload(array $data, ?Address $existing = null): array
    {
        if (isset($data['type_master_id'])) {
            $this->assertAddressTypeMaster((int) $data['type_master_id']);
        } elseif ($existing === null) {
            throw ValidationException::withMessages([
                'type_master_id' => 'Address type is required.',
            ]);
        }

        $areaTownId = $data['area_town_id'] ?? $existing?->area_town_id;
        $pinCode = $data['pin_code'] ?? $existing?->pin_code;

        if ($areaTownId === null) {
            throw ValidationException::withMessages([
                'area_town_id' => 'Area/town is required.',
            ]);
        }

        $areaTown = CityPinCode::query()->find($areaTownId);

        if (! $areaTown) {
            throw ValidationException::withMessages([
                'area_town_id' => 'Invalid area/town.',
            ]);
        }

        if (filled($pinCode) && (string) $areaTown->pin_code !== (string) $pinCode) {
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
            'type_master_id' => $data['type_master_id'] ?? $existing?->type_master_id,
            'street' => $street,
            'pin_code' => $pinCode ?? $areaTown->pin_code,
            'area_town_id' => $areaTown->id,
            'city_id' => $data['city_id'] ?? $areaTown->city_id,
            'state_id' => $data['state_id'] ?? $areaTown->state_id,
            'country_id' => $data['country_id'] ?? $areaTown->country_id,
            'is_primary' => (bool) ($data['is_primary'] ?? $existing?->is_primary ?? false),
            'sort' => $data['sort'] ?? $existing?->sort ?? 1,
        ];
    }

    private function afterAddressSaved(AccountMaster $account, Address $address): void
    {
        $company = Company::query()->where('account_master_id', $account->id)->first();

        if ($company && $address->company_id !== $company->id) {
            $address->company_id = $company->id;
            $address->save();
        }

        if (! $account->territory_id && filled($address->pin_code)) {
            $territoryId = TerritoryService::fromPinCode((string) $address->pin_code);

            if ($territoryId) {
                $account->update(['territory_id' => $territoryId]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncRelatedDetails(AccountMaster $account, array $data): void
    {
        if (array_key_exists('gst_detail', $data) && is_array($data['gst_detail'])) {
            $this->upsertGstDetail($account, $data['gst_detail']);
        }

        if (array_key_exists('credit_detail', $data) && is_array($data['credit_detail'])) {
            $this->upsertCreditDetail($account, $data['credit_detail']);
        }

        if (array_key_exists('statutory_detail', $data) && is_array($data['statutory_detail'])) {
            $this->upsertStatutoryDetail($account, $data['statutory_detail']);
        }

        if (array_key_exists('bank_details', $data) && is_array($data['bank_details'])) {
            foreach ($data['bank_details'] as $bankData) {
                if (! is_array($bankData)) {
                    continue;
                }

                $this->upsertBankDetail($account, $bankData);
            }
        }

        if (array_key_exists('bank_detail', $data) && is_array($data['bank_detail'])) {
            $this->upsertBankDetail($account, $data['bank_detail']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertGstDetail(AccountMaster $account, array $data): void
    {
        if (isset($data['address_id'])) {
            $belongs = $account->addresses()->whereKey($data['address_id'])->exists();

            if (! $belongs) {
                throw ValidationException::withMessages([
                    'gst_detail.address_id' => 'GST address must belong to this account.',
                ]);
            }

            if (blank($data['state_name'] ?? null) || blank($data['state_code'] ?? null)) {
                $address = Address::query()->with('state')->find($data['address_id']);
                $data['state_name'] = $data['state_name'] ?? $address?->state?->name;
                $data['state_code'] = $data['state_code'] ?? $address?->state?->gst_code;
            }
        }

        $account->gstDetail()->updateOrCreate(
            ['account_master_id' => $account->id],
            collect($data)->only([
                'address_id',
                'gst_number',
                'state_name',
                'state_code',
                'gst_type',
                'gst_status',
                'pan_number',
                'remark',
            ])->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertCreditDetail(AccountMaster $account, array $data): void
    {
        $account->creditDetail()->updateOrCreate(
            ['account_master_id' => $account->id],
            collect($data)->only([
                'credit_type',
                'credit_days',
                'credit_limit',
                'credit_status',
                'credit_review_date',
                'credit_terms',
                'remark',
            ])->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertStatutoryDetail(AccountMaster $account, array $data): void
    {
        $account->statutoryDetail()->updateOrCreate(
            ['account_master_id' => $account->id],
            collect($data)->only([
                'tan_number',
                'cin',
                'tds_parameters',
                'tds_section',
                'tds_rate',
                'tds_type',
                'tds_status',
                'is_tds_deduct',
                'is_tds_compulsory',
                'tds_remark',
            ])->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertBankDetail(AccountMaster $account, array $data): void
    {
        $attributes = collect($data)->only([
            'bank_name',
            'bank_account_number',
            'bank_account_name',
            'bank_account_ifsc_code',
            'bank_account_branch',
            'bank_account_swift_code',
            'bank_account_iban',
            'bank_account_bic',
            'bank_account_phone',
            'bank_account_email',
            'bank_account_address',
            'bank_account_city',
            'bank_account_state',
            'bank_account_country',
            'bank_account_zip',
            'bank_account_tax_id',
            'bank_account_micr_code',
            'bank_account_rtgs_code',
            'bank_account_ecs_code',
            'bank_account_code',
            'bank_account_type',
            'bank_account_currency',
            'bank_account_status',
            'is_primary',
            'remark',
        ])->all();

        if (isset($data['id'])) {
            $bank = $account->bankDetail()->find($data['id']);

            if (! $bank) {
                throw ValidationException::withMessages([
                    'bank_detail.id' => 'Bank detail not found on this account.',
                ]);
            }

            $bank->update($attributes);

            return;
        }

        $account->bankDetail()->create($attributes);
    }
}
