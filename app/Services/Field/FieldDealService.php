<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\ContactDetail;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\TypeMaster;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FieldDealService
{
    /**
     * @return list<string>
     */
    private function detailRelations(): array
    {
        return ['owner:id,name', 'status', 'accountMaster:id,name', 'contactDetail', 'type', 'address:id,street'];
    }

    /**
     * @return LengthAwarePaginator<int, Deal>
     */
    public function list(User $user, ?string $search = null, int $perPage = 50): LengthAwarePaginator
    {
        abort_unless($user->can('viewAny', Deal::class), 403);

        $query = Deal::query()
            ->with(['owner:id,name', 'status', 'accountMaster:id,name', 'contactDetail', 'type'])
            ->applyVisibility('Deal')
            ->latest('transaction_date')
            ->orderByDesc('id');

        if (filled($search)) {
            $term = '%'.$search.'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('deal_name', 'like', $term)
                    ->orWhere('reference_code', 'like', $term);
            });
        }

        return $query->paginate($perPage > 0 ? min($perPage, 100) : 50);
    }

    public function findVisible(User $user, int $dealId): Deal
    {
        abort_unless($user->can('viewAny', Deal::class), 403);

        $deal = Deal::query()
            ->with($this->detailRelations())
            ->applyVisibility('Deal')
            ->find($dealId);

        abort_if($deal === null, 404, 'Deal not found.');
        abort_unless($user->can('view', $deal), 403);

        return $deal;
    }

    /**
     * @return array{
     *     can_create: bool,
     *     stages: Collection<int, DealStage>,
     *     types: Collection<int, TypeMaster>
     * }
     */
    public function options(User $user): array
    {
        abort_unless($user->can('viewAny', Deal::class), 403);

        return [
            'can_create' => $user->can('create', Deal::class),
            'stages' => DealStage::query()
                ->orderBy('order')
                ->orderBy('name')
                ->get(['id', 'name', 'order']),
            'types' => TypeMaster::query()
                ->ofType(Deal::class)
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    public function canUpdate(User $user, Deal $deal): bool
    {
        return $user->can('update', $deal);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(User $user, array $data): Deal
    {
        abort_unless($user->can('create', Deal::class), 403);

        $this->assertDealStage((int) $data['status_id']);
        $this->assertDealType((int) $data['type_id']);
        $this->assertDealRelations($user, [
            'account_master_id' => $data['account_master_id'] ?? null,
            'contact_detail_id' => $data['contact_detail_id'] ?? null,
            'address_id' => $data['address_id'] ?? null,
        ]);

        $deal = Deal::query()->create([
            'deal_name' => $data['deal_name'],
            'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
            'owner_id' => $user->id,
            'contact_detail_id' => $data['contact_detail_id'] ?? null,
            'account_master_id' => $data['account_master_id'] ?? null,
            'address_id' => $data['address_id'] ?? null,
            'type_id' => $data['type_id'],
            'type_type' => TypeMaster::class,
            'amount' => $data['amount'],
            'expected_revenue' => $data['expected_revenue'],
            'expected_close_date' => $data['expected_close_date'],
            'lead_source_id' => $data['lead_source_id'] ?? null,
            'description' => $data['description'] ?? null,
            'status_id' => $data['status_id'],
            'status_type' => DealStage::class,
        ]);

        return $deal->fresh($this->detailRelations());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, Deal $deal, array $data): Deal
    {
        abort_unless($user->can('update', $deal), 403);

        if (isset($data['status_id'])) {
            $this->assertDealStage((int) $data['status_id']);
        }

        if (isset($data['type_id'])) {
            $this->assertDealType((int) $data['type_id']);
        }

        $this->assertDealRelations($user, $this->resolveRelationIds($data, $deal), $deal->contact_detail_id);

        $payload = collect($data)->only([
            'deal_name',
            'transaction_date',
            'contact_detail_id',
            'account_master_id',
            'address_id',
            'type_id',
            'amount',
            'expected_revenue',
            'expected_close_date',
            'lead_source_id',
            'description',
            'status_id',
        ])->all();

        if (array_key_exists('status_id', $payload)) {
            $payload['status_type'] = DealStage::class;
        }

        if (array_key_exists('type_id', $payload)) {
            $payload['type_type'] = TypeMaster::class;
        }

        $deal->fill($payload)->save();

        return $deal->fresh($this->detailRelations());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{account_master_id: mixed, contact_detail_id: mixed, address_id: mixed}
     */
    private function resolveRelationIds(array $data, Deal $existing): array
    {
        return [
            'account_master_id' => array_key_exists('account_master_id', $data)
                ? $data['account_master_id']
                : $existing->account_master_id,
            'contact_detail_id' => array_key_exists('contact_detail_id', $data)
                ? $data['contact_detail_id']
                : $existing->contact_detail_id,
            'address_id' => array_key_exists('address_id', $data)
                ? $data['address_id']
                : $existing->address_id,
        ];
    }

    /**
     * An unchanged existing contact is kept without a visibility check.
     *
     * @param  array{account_master_id: mixed, contact_detail_id: mixed, address_id: mixed}  $ids
     */
    private function assertDealRelations(User $user, array $ids, ?int $existingContactId = null): void
    {
        $accountId = $ids['account_master_id'] !== null ? (int) $ids['account_master_id'] : null;
        $contactId = $ids['contact_detail_id'] !== null ? (int) $ids['contact_detail_id'] : null;
        $addressId = $ids['address_id'] !== null ? (int) $ids['address_id'] : null;

        if ($accountId !== null) {
            $this->assertVisibleAccount($user, $accountId);
        }

        if ($contactId !== null) {
            $contact = ContactDetail::query()->find($contactId);

            if ($contact === null) {
                throw ValidationException::withMessages([
                    'contact_detail_id' => 'Invalid contact.',
                ]);
            }

            if ($accountId !== null && ! $this->contactBelongsToAccount($contact, $accountId)) {
                throw ValidationException::withMessages([
                    'contact_detail_id' => 'Contact does not belong to the selected account.',
                ]);
            }

            if (
                $accountId === null
                && $contactId !== $existingContactId
                && ! ContactDetail::query()->applyVisibility('ContactDetail', $user)->whereKey($contactId)->exists()
            ) {
                throw ValidationException::withMessages([
                    'contact_detail_id' => 'Select a contact you can access.',
                ]);
            }
        }

        if ($addressId === null) {
            return;
        }

        $address = Address::query()->find($addressId);

        if ($address === null) {
            throw ValidationException::withMessages([
                'address_id' => 'Invalid address.',
            ]);
        }

        if ($contactId !== null) {
            if (! $this->addressBelongsToContact($address, $contactId)) {
                throw ValidationException::withMessages([
                    'address_id' => 'Address does not belong to the selected contact.',
                ]);
            }

            return;
        }

        if ($accountId !== null) {
            if (! $this->addressBelongsToAccount($address, $accountId)) {
                throw ValidationException::withMessages([
                    'address_id' => 'Address does not belong to the selected account.',
                ]);
            }

            return;
        }

        throw ValidationException::withMessages([
            'address_id' => 'Address requires a related account or contact.',
        ]);
    }

    private function contactBelongsToAccount(ContactDetail $contact, int $accountId): bool
    {
        if ((int) $contact->account_master_id === $accountId) {
            return true;
        }

        return $contact->accountMasters()->whereKey($accountId)->exists();
    }

    private function addressBelongsToContact(Address $address, int $contactId): bool
    {
        if ((int) $address->contact_detail_id === $contactId) {
            return true;
        }

        return $address->addressable_type === ContactDetail::class
            && (int) $address->addressable_id === $contactId;
    }

    private function addressBelongsToAccount(Address $address, int $accountId): bool
    {
        if (
            $address->addressable_type === AccountMaster::class
            && (int) $address->addressable_id === $accountId
        ) {
            return true;
        }

        return $address->accountMasters()->whereKey($accountId)->exists();
    }

    private function assertDealStage(int $statusId): void
    {
        $exists = DealStage::query()->whereKey($statusId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'status_id' => 'Invalid deal stage.',
            ]);
        }
    }

    private function assertDealType(int $typeId): void
    {
        $exists = TypeMaster::query()
            ->ofType(Deal::class)
            ->whereKey($typeId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'type_id' => 'Invalid deal type.',
            ]);
        }
    }

    private function assertVisibleAccount(User $user, int $accountId): void
    {
        abort_unless($user->can('viewAny', AccountMaster::class), 403);

        $account = AccountMaster::query()
            ->applyVisibility('AccountMaster')
            ->find($accountId);

        abort_if($account === null, 404, 'Account not found.');
        abort_unless($user->can('view', $account), 403);
    }
}
