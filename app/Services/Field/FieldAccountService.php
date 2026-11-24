<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\AccountReportingMaster;
use App\Models\AccountStatus;
use App\Models\Address;
use App\Models\CityPinCode;
use App\Models\Company;
use App\Models\ContactDetail;
use App\Models\OrganizationalUnit;
use App\Models\Patch;
use App\Models\Territory;
use App\Models\TypeMaster;
use App\Models\User;
use App\Services\PositionService;
use App\Services\Reports\ReportVisibility;
use App\Services\TerritoryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
                $record = $id ? $account->$relation()->find($id) : null;
                if ($id && ! $record) {
                    throw ValidationException::withMessages([$field => 'The reporting record does not belong to this account.']);
                }

                $divisionId = $values['division_ou_id'] ?? null;
                if ($field === 'division_profiles' && $divisionId && (int) $divisionId !== (int) $record?->division_ou_id
                    && ! OrganizationalUnit::query()->activeDivisions()->whereKey($divisionId)->exists()) {
                    throw ValidationException::withMessages(['division_profiles' => 'Choose an active division.']);
                }

                if ($record) {
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
            'typeMaster', 'owner:id,name',
            'specialty', 'therapyArea', 'accountStatus', 'divisionProfiles.division', 'divisionProfiles.ratingType', 'divisionProfiles.potentialUnit',
            'callTargets', 'assignmentHistory.owner', 'assignmentHistory.territory', 'approval',
            'territory',
            'contactDetails.approval',
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
        array $register = [],
    ): LengthAwarePaginator {
        abort_unless($user->can('viewAny', AccountMaster::class), 403);

        $query = $this->visibleAccounts($user)
            ->with(['typeMaster', 'territory', 'contactDetails'])
            ->orderBy('name')->orderBy('id');

        if ($register !== []) {
            $query->with(['specialty:id,name', 'therapyArea:id,name', 'accountStatus:id,name', 'approval']);
            match ($register['trash'] ?? 'active') {
                'only' => $query->onlyTrashed(),
                'with' => $query->withTrashed(),
                default => $query,
            };
            foreach (['specialty_id', 'therapy_area_id', 'account_status_id'] as $filter) {
                if (isset($register[$filter])) {
                    $query->where($filter, $register[$filter]);
                }
            }
        }

        if (filled($search)) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->whereLike('name', '%'.$search.'%')
                    ->orWhereLike('account_code', '%'.$search.'%')
                    ->orWhereLike('phone_number', '%'.$search.'%')
                    ->orWhereLike('email', '%'.$search.'%')
                    ->orWhereLike('external_reference', '%'.$search.'%')
                    ->orWhereLike('alias', '%'.$search.'%');
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

    public function findVisible(User $user, int $accountId, bool $withTrash = false): AccountMaster
    {
        abort_unless($user->can('viewAny', AccountMaster::class), 403);

        $account = $this->visibleAccounts($user)
            ->withTrashed($withTrash)
            ->with($this->detailRelations())
            ->find($accountId);

        if (! $account) {
            abort(404, 'Account not found.');
        }

        abort_unless($user->can('view', $account), 403);

        return $account;
    }

    private function visibleAccounts(User $actor): Builder
    {
        return AccountMaster::query()->applyVisibility('AccountMaster', $actor);
    }

    public function registerOptions(User $actor): array
    {
        abort_unless($actor->can('viewAny', AccountMaster::class), 403);
        $visible = $this->visibleAccounts($actor)->withTrashed();

        return [
            'types' => TypeMaster::query()->ofType(AccountMaster::class)->orderBy('name')->get(['id', 'name']),
            'territories' => Territory::query()->whereIn('id', (clone $visible)->select('territory_id'))->orderBy('name')->get(['id', 'name']),
            'patches' => Patch::query()->whereHas('companies', fn (Builder $accounts): Builder => $accounts
                ->withTrashed()->whereIn('account_masters.id', (clone $visible)->select('account_masters.id')))->orderBy('name')->get(['id', 'name']),
            'specialties' => AccountReportingMaster::query()->where('kind', 'specialty')->orderBy('name')->get(['id', 'name']),
            'therapy_areas' => AccountReportingMaster::query()->where('kind', 'therapy_area')->orderBy('name')->get(['id', 'name']),
            'statuses' => AccountStatus::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function lifecycleActions(User $actor, AccountMaster $account): array
    {
        if ($actor->cannot('view', $account) || $this->isLocked($actor, $account)) {
            return [];
        }

        return array_values(array_filter($account->trashed() ? ['restore', 'forceDelete'] : ['delete'],
            fn (string $action): bool => $actor->can($action, $account)));
    }

    private function isLocked(User $actor, AccountMaster $account): bool
    {
        return $account->approval_status === 'pending' || $account->approval?->approval_status === 'pending'
            || ($account->approval_status === 'approved' && $actor->cannot('overrideApproval', $account));
    }

    public function canEdit(User $actor, AccountMaster $account): bool
    {
        return ! $account->trashed() && $actor->can('update', $account) && ! $this->isLocked($actor, $account);
    }

    public function ownerOptions(User $actor, string $search): LengthAwarePaginator
    {
        abort_unless($actor->can('viewAny', AccountMaster::class) && FieldTeamOnboardingService::canManage($actor), 403);

        return User::query()->when(filled($search), fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(25, ['id', 'name']);
    }

    private function assignedOwner(User $actor, array $data, int $default): int
    {
        if (! isset($data['assign_owner_id'])) {
            return $default;
        }

        abort_unless(FieldTeamOnboardingService::canManage($actor) && $actor->can('viewAny', AccountMaster::class), 403);
        $ownerId = (int) $data['assign_owner_id'];
        if (! User::query()->whereKey($ownerId)->exists()) {
            throw ValidationException::withMessages(['assign_owner_id' => 'Choose a user in this company.']);
        }

        return $ownerId;
    }

    public function applyLifecycle(User $actor, string $action, array $ids): int
    {
        abort_unless($actor->can('viewAny', AccountMaster::class), 403);
        if (count($ids) > 1 && $action !== 'delete') {
            abort_unless($actor->can($action.'Any', AccountMaster::class), 403);
        }

        return $actor->getConnection()->transaction(function () use ($actor, $action, $ids): int {
            $accounts = $this->visibleAccounts($actor)->withTrashed()->whereKey($ids)->orderBy('id')->with('approval')->lockForUpdate()->get();
            abort_unless($accounts->count() === count($ids), 404);
            foreach ($accounts as $account) {
                abort_unless($actor->can('view', $account) && $actor->can($action, $account), 403);
                abort_unless($account->trashed() === ($action !== 'delete'), 409, 'The account changed. Refresh the register.');
                abort_if($this->isLocked($actor, $account), 422, 'An account is locked by approval.');
                if ($action === 'forceDelete') {
                    $this->assertNoAccountHistory($account);
                }
            }
            foreach ($accounts as $account) {
                $changed = match ($action) {
                    'delete' => $account->delete(),
                    'restore' => $account->restore(),
                    'forceDelete' => $account->forceDelete(),
                };
                abort_unless($changed, 409, 'The account could not be updated.');
            }

            return $accounts->count();
        }, attempts: 3);
    }

    private function assertNoAccountHistory(AccountMaster $account): void
    {
        $message = 'Accounts with linked records or history must stay in trash.';
        abort_if($account->assignmentHistory()->skip(1)->take(1)->get(['id'])->isNotEmpty(), 422, $message);
        abort_if($account->approvals()->withTrashed()->exists() || $account->visitables()->exists()
            || $account->addresses()->withTrashed()->exists() || $account->bankDetail()->withTrashed()->exists(), 422, $message);

        $references = [
            'account_masters' => ['parent_id', 'institution_account_id', 'supplier_account_id'],
            'account_master_contact_details' => ['account_master_id'],
            'account_master_address_details' => ['account_master_id'],
            'account_master_g_s_t_details' => ['account_master_id'],
            'account_master_credit_details' => ['account_master_id'],
            'account_master_statutory_details' => ['account_master_id'],
            'account_call_targets' => ['account_master_id'],
            'account_division_profiles' => ['account_master_id'],
            'account_master_patch' => ['account_master_id'],
            'item_master_account_masters' => ['account_master_id'],
            'companies' => ['account_master_id'],
            'leads' => ['account_master_id'],
            'deals' => ['account_master_id'],
            'quotes' => ['account_master_id'],
            'sales_orders' => ['account_master_id'],
            'sales_invoices' => ['account_master_id'],
            'delivery_challans' => ['customer_id'],
            'purchase_orders' => ['supplier_id'],
            'goods_receipt_notes' => ['supplier_id'],
            'customer_prices' => ['customer_id'],
            'sgip_distributions' => ['account_master_id'],
            'flexible_plan_entries' => ['account_master_id'],
            'travel_segments' => ['from_account_id', 'to_account_id'],
            'secondary_sales_statements' => ['stockist_id'],
            'secondary_ocr_sources' => ['stockist_id'],
            'rcpa_observations' => ['chemist_id'],
            'contact_details' => ['account_master_id'],
        ];
        foreach ($references as $table => $columns) {
            abort_if($account->getConnection()->table($table)->whereAny($columns, '=', $account->id)->exists(), 422, $message);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(User $user, array $data): AccountMaster
    {
        abort_unless($user->can('create', AccountMaster::class), 403);

        $this->assertTypeMaster((int) $data['type_master_id']);
        $this->assertAssignableRelations($user, $data);

        return DB::transaction(function () use ($user, $data): AccountMaster {
            $account = AccountMaster::query()->create([
                'name' => $data['name'],
                'geo_latitude' => $data['geo_latitude'] ?? null,
                'geo_longitude' => $data['geo_longitude'] ?? null,
                'geo_radius_meters' => $data['geo_radius_meters'] ?? 100,
                'rcpa_tier' => $data['rcpa_tier'] ?? null,
                'accept_secondary_statements' => $data['accept_secondary_statements'] ?? false,
                'type_master_id' => $data['type_master_id'],
                'owner_id' => $this->assignedOwner($user, $data, $user->id),
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

        return DB::transaction(function () use ($user, $account, $data): AccountMaster {
            $account = $this->lockWritable($user, $account);
            $this->assertAssignableRelations($user, $data, $account);
            if (isset($data['assign_owner_id'])) {
                $account->owner_id = $this->assignedOwner($user, $data, $account->owner_id ?? $user->id);
            }
            $account->fill(collect($data)->only([
                'geo_latitude', 'geo_longitude', 'geo_radius_meters',
                'rcpa_tier',
                'accept_secondary_statements',
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

    private function lockWritable(User $actor, AccountMaster $account): AccountMaster
    {
        $record = $this->visibleAccounts($actor)->with('approval')->lockForUpdate()->findOrFail($account->id);
        abort_unless($actor->can('update', $record), 403);
        abort_if($this->isLocked($actor, $record), 422, 'This account is locked by approval.');

        return $record;
    }

    public function changeContact(User $actor, AccountMaster $account, string $action, ?int $id = null, array $data = []): AccountMaster
    {
        return $account->getConnection()->transaction(function () use ($actor, $account, $action, $id, $data): AccountMaster {
            $account = $this->lockWritable($actor, $account);
            if ($action === 'create') {
                abort_unless($actor->can('create', ContactDetail::class), 403);
                $contact = ContactDetail::query()->create([
                    ...$data,
                    'company_id' => Company::query()->where('account_master_id', $account->id)->value('id'),
                ]);
                $account->contactDetails()->attach($contact);
            } elseif ($action === 'detach') {
                abort_unless($account->contactDetails()->detach($id) > 0, 404);
            } else {
                abort_unless($actor->can('viewAny', ContactDetail::class), 403);
                $contact = ContactDetail::query()->applyVisibility('ContactDetail', $actor)->lockForUpdate()->findOrFail($id);
                abort_unless($actor->can('view', $contact), 403);
                if ($action === 'attach') {
                    $account->contactDetails()->syncWithoutDetaching([$contact->id]);
                } else {
                    abort_unless($account->contactDetails()->whereKey($contact->id)->exists(), 404);
                    app(FieldContactService::class)->assertCanWrite($actor, $contact, 'update');
                    $contact->update($data);
                }
            }

            return $account->load($this->detailRelations());
        }, attempts: 3);
    }

    public function applyRelatedAction(User $actor, AccountMaster $account, string $group, string $action, array $ids): int
    {
        abort_if($action === 'detach' && ! in_array($group, ['contacts', 'addresses'], true), 422);

        return $account->getConnection()->transaction(function () use ($actor, $account, $group, $action, $ids): int {
            $account = $this->lockWritable($actor, $account);
            $relation = match ($group) {
                'contacts' => $account->contactDetails(),
                'addresses' => $account->addresses(),
                'bank' => $account->bankDetail(),
                'credit' => $account->creditDetail(),
                'gst' => $account->gstDetail(),
                'statutory' => $account->statutoryDetail(),
            };
            $records = $relation->whereKey($ids)->orderBy($relation->getRelated()->getQualifiedKeyName())->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);
            if ($group === 'addresses') {
                abort_if($account->gstDetail()->withTrashed()->whereIn('address_id', $ids)->exists(), 422, 'Remove the GST address reference before removing the address.');
            }
            foreach ($records as $record) {
                if ($record instanceof ContactDetail && $action === 'delete') {
                    $this->assertContactDeletable($actor, $account, $record);
                }
                abort_if($action === 'delete' && ($record->approval_status === 'pending'
                    || ($record->approval_status === 'approved' && $actor->cannot('OverrideApproval:'.class_basename($record)))), 422, 'A related record is locked by approval.');
            }
            foreach ($records as $record) {
                if ($action === 'delete') {
                    abort_unless($record->delete(), 409);
                } elseif ($group === 'addresses') {
                    $record->addressable()->dissociate();
                    abort_unless($record->save(), 409);
                }
            }
            if ($group === 'contacts') {
                $account->contactDetails()->detach($ids);
            }

            return $records->count();
        }, attempts: 3);
    }

    private function assertContactDeletable(User $actor, AccountMaster $account, ContactDetail $contact): void
    {
        abort_unless($actor->can(['ViewAny:ContactDetail', 'View:ContactDetail', 'Delete:ContactDetail']), 403);
        abort_unless(ContactDetail::query()->applyVisibility('ContactDetail', $actor)->whereKey($contact->id)->exists(), 404);
        app(FieldContactService::class)->assertCanWrite($actor, $contact, 'delete');
        abort_if($contact->company_id || $contact->addresses()->withTrashed()->exists(), 422, 'This contact has other links. Unlink it from this account instead.');
        app(FieldContactService::class)->assertNoSharedLinks($contact, $account->id);
    }

    /** @param array<string, mixed> $data */
    private function assertAssignableRelations(User $user, array $data, ?AccountMaster $account = null): void
    {
        foreach (['parent_id', 'institution_account_id', 'supplier_account_id'] as $field) {
            if (! isset($data[$field]) || (int) $data[$field] === (int) $account?->getAttribute($field)) {
                continue;
            }

            $related = AccountMaster::query()->applyVisibility('AccountMaster', $user)->find($data[$field]);
            if (! $related || ! $user->can('view', $related) || $related->is($account)) {
                throw ValidationException::withMessages([$field => 'Choose an accessible related account.']);
            }
        }

        if (isset($data['ref_dealer_contact']) && (int) $data['ref_dealer_contact'] !== (int) $account?->ref_dealer_contact) {
            $contact = ContactDetail::query()->applyVisibility('ContactDetail', $user)->find($data['ref_dealer_contact']);
            if (! $contact || ! $user->can('viewAny', ContactDetail::class) || ! $user->can('view', $contact)) {
                throw ValidationException::withMessages(['ref_dealer_contact' => 'Choose an accessible contact.']);
            }
        }

        if (! array_key_exists('territory_id', $data) && ! array_key_exists('patch_ids', $data)) {
            return;
        }

        $territoryId = array_key_exists('territory_id', $data) ? $data['territory_id'] : $account?->territory_id;
        $allowed = ReportVisibility::hasFullAccess($user) ? null : PositionService::getTerritoryIdsForUser($user);
        if ($territoryId !== null && (int) $territoryId !== (int) $account?->territory_id
            && ! Territory::query()->whereKey($territoryId)->when($allowed !== null, fn ($query) => $query->whereKey($allowed))->exists()) {
            throw ValidationException::withMessages(['territory_id' => 'Choose a territory assigned to your position.']);
        }

        if (! array_key_exists('patch_ids', $data)) {
            return;
        }

        $existingPatchIds = (int) $territoryId === (int) $account?->territory_id
            ? ($account?->patches()->pluck('patches.id')->map(fn ($id): int => (int) $id)->all() ?? [])
            : [];
        $patchIds = array_unique(array_map('intval', $data['patch_ids'] ?? []));
        $patchIds = array_values(array_diff($patchIds, $existingPatchIds));
        if ($patchIds !== [] && Patch::query()->whereKey($patchIds)
            ->when($territoryId !== null, fn ($query) => $query->where('territory_id', $territoryId))
            ->when($allowed !== null, fn ($query) => $query->whereIn('territory_id', $allowed))
            ->count() !== count($patchIds)) {
            throw ValidationException::withMessages(['patch_ids' => 'Choose accessible patches in the selected territory.']);
        }
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

        return DB::transaction(fn (): Address => $this->createAddressForAccount($this->lockWritable($user, $account), $data)
            ->load(['typeMaster', 'areaTown', 'city', 'state', 'country']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAddress(User $user, AccountMaster $account, Address $address, array $data): Address
    {
        abort_unless($user->can('update', $account), 403);
        $this->assertAddressBelongsToAccount($account, $address);

        return DB::transaction(function () use ($user, $account, $address, $data): Address {
            $account = $this->lockWritable($user, $account);
            $address = $account->addresses()->lockForUpdate()->findOrFail($address->id);
            $payload = app(FieldAddressPayload::class)->normalize($data, existing: $address);
            $address->fill($payload)->save();
            $this->afterAddressSaved($account, $address);

            return $address->fresh(['typeMaster', 'areaTown', 'city', 'state', 'country']);
        });
    }

    public function deleteAddress(User $user, AccountMaster $account, Address $address): void
    {
        abort_unless($user->can('update', $account), 403);
        $this->assertAddressBelongsToAccount($account, $address);

        DB::transaction(function () use ($user, $account, $address): void {
            $this->lockWritable($user, $account)->addresses()->lockForUpdate()->findOrFail($address->id)->delete();
        });
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
        $payload = app(FieldAddressPayload::class)->normalize($data);

        $address = $account->addresses()->create($payload);
        $this->afterAddressSaved($account, $address);

        return $address;
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
