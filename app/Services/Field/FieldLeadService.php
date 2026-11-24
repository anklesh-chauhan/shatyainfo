<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\Approval;
use App\Models\ApprovalOverride;
use App\Models\ContactDetail;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\ItemMaster;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadNote;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Territory;
use App\Models\User;
use App\Services\PositionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldLeadService
{
    public const CONVERTED_STATUS = 'Converted';

    public const CONVERTED_DEAL_STAGE = 'Negotiation/Review';

    /**
     * @return list<string>
     */
    private function detailRelations(): array
    {
        return [
            'owner:id,name',
            'status',
            'accountMaster:id,name',
            'contactDetail',
            'leadSource:id,name',
            'territory:id,name',
            'address:id,street',
            'approval',
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Lead>
     */
    public function list(User $user, ?string $search = null, int $perPage = 50): LengthAwarePaginator
    {
        abort_unless($user->can('viewAny', Lead::class), 403);

        $query = Lead::query()
            ->with(['owner:id,name', 'status', 'accountMaster:id,name', 'contactDetail', 'leadSource:id,name', 'approval'])
            ->applyVisibility('Lead', $user)
            ->latest('transaction_date')
            ->orderByDesc('id');

        if (filled($search)) {
            $term = '%'.$search.'%';
            $query->where(function ($builder) use ($term): void {
                $builder->whereLike('reference_code', $term)
                    ->orWhereLike('description', $term)
                    ->orWhereHas('accountMaster', fn ($accounts) => $accounts->whereLike('name', $term))
                    ->orWhereHas('contactDetail', function ($contacts) use ($term): void {
                        $contacts->whereLike('first_name', $term)
                            ->orWhereLike('last_name', $term);
                    });
            });
        }

        return $query->paginate($perPage > 0 ? min($perPage, 100) : 50);
    }

    public function findVisible(User $user, int $leadId): Lead
    {
        abort_unless($user->can('viewAny', Lead::class), 403);

        $lead = Lead::query()
            ->with($this->detailRelations())
            ->applyVisibility('Lead', $user)
            ->find($leadId);

        abort_if($lead === null, 404, 'Lead not found.');
        abort_unless($user->can('view', $lead), 403);

        return $lead;
    }

    /**
     * All activities of a visible lead, as in the Admin lead relation manager.
     *
     * @return LengthAwarePaginator<int, LeadActivity>
     */
    public function activities(User $user, int $leadId, int $perPage = 50): LengthAwarePaginator
    {
        abort_unless($user->can('viewAny', LeadActivity::class), 403);

        $lead = $this->findVisible($user, $leadId);

        return $lead->leadActivities()
            ->with(['approval', 'user:id,name'])
            ->chaperone()
            ->latest()
            ->orderByDesc('id')
            ->paginate($perPage > 0 ? min($perPage, 100) : 50);
    }

    /**
     * All notes of a visible lead. `LeadNote` has no policy; access follows
     * the lead (user decision, M01-G04-01g).
     *
     * @return LengthAwarePaginator<int, LeadNote>
     */
    public function notes(User $user, int $leadId, int $perPage = 50): LengthAwarePaginator
    {
        $lead = $this->findVisible($user, $leadId);

        return $lead->leadNotes()
            ->with(['user:id,name', 'attachments:id,attachable_id,attachable_type,file_name,file_type'])
            ->latest()
            ->orderByDesc('id')
            ->paginate($perPage > 0 ? min($perPage, 100) : 50);
    }

    /**
     * Items linked to a visible lead. Gate matches Admin's lead items panel:
     * `ItemMasterPolicy::viewAny`.
     *
     * @return Collection<int, ItemMaster>
     */
    public function items(User $user, int $leadId): Collection
    {
        abort_unless($user->can('viewAny', ItemMaster::class), 403);

        $lead = $this->findVisible($user, $leadId);

        return $lead->itemMasters()
            ->orderBy('item_name')
            ->get(['item_masters.id', 'item_masters.item_code', 'item_masters.item_name']);
    }

    /**
     * Text-only note by the caller, logged like Admin's "Note Added".
     */
    public function addNote(User $user, Lead $lead, string $note): LeadNote
    {
        abort_unless($user->can('update', $lead), 403);

        $created = DB::transaction(function () use ($user, $lead, $note): LeadNote {
            $created = $lead->leadNotes()->create([
                'user_id' => $user->id,
                'note' => $note,
            ]);

            LeadActivity::query()->create([
                'lead_id' => $lead->id,
                'user_id' => $user->id,
                'activity_type' => 'Note Added',
                'description' => "Note '{$note}' has been added to the lead",
            ]);

            return $created;
        });

        return $created->fresh(['user:id,name', 'attachments']);
    }

    /**
     * @return array{
     *     can_create: bool,
     *     statuses: Collection<int, LeadStatus>,
     *     sources: Collection<int, LeadSource>,
     *     territories: Collection<int, Territory>
     * }
     */
    public function options(User $user): array
    {
        abort_unless($user->can('viewAny', Lead::class), 403);

        return [
            'can_create' => $user->can('create', Lead::class),
            'statuses' => LeadStatus::query()
                ->orderBy('order')
                ->orderBy('name')
                ->get(['id', 'name', 'order']),
            'sources' => LeadSource::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'territories' => $this->assignableTerritories($user)
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    /**
     * Territories the user may place a lead in: all for elevated roles,
     * otherwise the position territories used by `ViewOwnTerritory:Lead`.
     *
     * @return Builder<Territory>
     */
    private function assignableTerritories(User $user): Builder
    {
        $query = Territory::query();

        if (! $user->hasAnyRole(['super_admin', 'administration_admin']) && ! $user->can('AccessAllRecords')) {
            $query->whereIn('id', PositionService::getTerritoryIdsForUser($user));
        }

        return $query;
    }

    private function assertAssignableTerritory(User $user, int $territoryId): void
    {
        if (! $this->assignableTerritories($user)->whereKey($territoryId)->exists()) {
            throw ValidationException::withMessages([
                'territory_id' => 'Select one of your assigned territories.',
            ]);
        }
    }

    public function canUpdate(User $user, Lead $lead): bool
    {
        return $user->can('update', $lead);
    }

    public function canDelete(User $user, Lead $lead): bool
    {
        return $user->can(['view', 'delete'], $lead)
            && (! $this->requiresDeleteOverride($lead) || $user->can('overrideApproval', $lead));
    }

    private function requiresDeleteOverride(Lead $lead): bool
    {
        return $lead->approval_status === 'approved' || $lead->approval?->approval_status === 'approved';
    }

    /** @param list<int> $ids */
    public function delete(User $user, array $ids): int
    {
        abort_unless($user->can('viewAny', Lead::class), 403);

        return $user->getConnection()->transaction(function () use ($user, $ids): int {
            $leads = Lead::query()->applyVisibility('Lead', $user)->whereKey($ids)
                ->with('approval')
                ->orderBy('id')->lockForUpdate()->get();

            abort_unless($leads->count() === count($ids), 404, 'One or more leads are unavailable.');

            $approvals = Approval::query()->whereKey($leads->pluck('approval.id')->filter()->all())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($leads as $lead) {
                $lead->setRelation('approval', $approvals->get($lead->approval?->id));
                abort_unless($user->can(['view', 'delete'], $lead), 403);
                abort_if($this->requiresDeleteOverride($lead) && $user->cannot('overrideApproval', $lead), 422,
                    'Approved leads require approval override to delete.');
            }

            foreach ($leads as $lead) {
                if ($lead->approval_status !== 'approved' && $lead->approval?->approval_status === 'approved') {
                    ApprovalOverride::query()->create([
                        'model_type' => Lead::class, 'model_id' => $lead->id, 'user_id' => $user->id,
                        'reason' => 'Lead deleted through the mobile API',
                    ]);
                }
                abort_unless($lead->delete(), 409, 'Could not delete the selected leads.');
            }

            return $leads->count();
        }, attempts: 3);
    }

    public function canConvert(User $user, Lead $lead): bool
    {
        return $user->can('update', $lead)
            && $user->can('create', Deal::class)
            && $lead->status?->name !== self::CONVERTED_STATUS;
    }

    /**
     * Mirrors Admin's `Lead::convertToDeal()` without the account-master
     * option: the account's type and code are never changed. The lead's
     * contact is copied to `contact_detail_id` (Admin passes a non-fillable
     * `contact_id`, so its deals lose the contact).
     */
    public function convert(User $user, Lead $lead): Deal
    {
        abort_unless($user->can('update', $lead), 403);
        abort_unless($user->can('create', Deal::class), 403);

        $convertedStatus = LeadStatus::query()->where('name', self::CONVERTED_STATUS)->first();

        if ($convertedStatus === null) {
            throw ValidationException::withMessages([
                'lead' => 'No "Converted" lead status is configured. Ask an administrator.',
            ]);
        }

        $stage = DealStage::query()->where('name', self::CONVERTED_DEAL_STAGE)->first();

        if ($stage === null) {
            throw ValidationException::withMessages([
                'lead' => 'No "'.self::CONVERTED_DEAL_STAGE.'" deal stage is configured. Ask an administrator.',
            ]);
        }

        return DB::transaction(function () use ($user, $lead, $convertedStatus, $stage): Deal {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->status_id === (int) $convertedStatus->id && $locked->status_type === LeadStatus::class) {
                throw ValidationException::withMessages([
                    'lead' => 'This lead is already converted.',
                ]);
            }

            $locked->loadMissing('accountMaster:id,name');

            $deal = Deal::query()->create([
                'owner_id' => $locked->owner_id,
                'deal_name' => $locked->accountMaster?->name ?? 'Unnamed Deal',
                'transaction_date' => now(),
                'contact_detail_id' => $locked->contact_detail_id,
                'account_master_id' => $locked->account_master_id,
                'address_id' => $locked->address_id,
                'amount' => $locked->annual_revenue ?? 0,
                'expected_revenue' => $locked->annual_revenue ?? 0,
                'expected_close_date' => now()->addDays(30),
                'description' => $locked->description,
                'status_id' => $stage->id,
                'status_type' => DealStage::class,
            ]);

            $locked->update([
                'status_id' => $convertedStatus->id,
                'status_type' => LeadStatus::class,
            ]);

            LeadActivity::query()->create([
                'lead_id' => $locked->id,
                'user_id' => $user->id,
                'activity_type' => 'Lead Converted',
                'description' => "Lead {$locked->reference_code} was converted to Deal {$deal->reference_code}.",
            ]);

            return $deal;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(User $user, array $data): Lead
    {
        abort_unless($user->can('create', Lead::class), 403);

        $this->assertLeadStatus((int) $data['status_id']);
        $this->assertLeadSource((int) $data['lead_source_id']);
        $this->assertLeadRelations($user, [
            'account_master_id' => $data['account_master_id'] ?? null,
            'contact_detail_id' => $data['contact_detail_id'] ?? null,
            'address_id' => $data['address_id'] ?? null,
        ]);

        if (isset($data['territory_id'])) {
            $this->assertAssignableTerritory($user, (int) $data['territory_id']);
        }

        $lead = Lead::query()->create([
            'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
            'owner_id' => $user->id,
            'contact_detail_id' => $data['contact_detail_id'] ?? null,
            'account_master_id' => $data['account_master_id'] ?? null,
            'address_id' => $data['address_id'] ?? null,
            'territory_id' => $data['territory_id'] ?? null,
            'lead_source_id' => $data['lead_source_id'],
            'annual_revenue' => $data['annual_revenue'] ?? null,
            'description' => $data['description'] ?? null,
            'status_id' => $data['status_id'],
            'status_type' => LeadStatus::class,
        ]);

        return $lead->fresh($this->detailRelations());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, Lead $lead, array $data): Lead
    {
        abort_unless($user->can('update', $lead), 403);

        if (isset($data['status_id'])) {
            $this->assertLeadStatus((int) $data['status_id']);
        }

        if (isset($data['lead_source_id'])) {
            $this->assertLeadSource((int) $data['lead_source_id']);
        }

        $this->assertLeadRelations($user, $this->resolveRelationIds($data, $lead), $lead->contact_detail_id);

        if (isset($data['territory_id']) && (int) $data['territory_id'] !== (int) $lead->territory_id) {
            $this->assertAssignableTerritory($user, (int) $data['territory_id']);
        }

        $payload = collect($data)->only([
            'transaction_date',
            'contact_detail_id',
            'account_master_id',
            'address_id',
            'territory_id',
            'lead_source_id',
            'annual_revenue',
            'description',
            'status_id',
        ])->all();

        if (array_key_exists('status_id', $payload)) {
            $payload['status_type'] = LeadStatus::class;
        }

        $lead->fill($payload)->save();

        return $lead->fresh($this->detailRelations());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{account_master_id: mixed, contact_detail_id: mixed, address_id: mixed}
     */
    private function resolveRelationIds(array $data, Lead $existing): array
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
    private function assertLeadRelations(User $user, array $ids, ?int $existingContactId = null): void
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

    private function assertLeadStatus(int $statusId): void
    {
        $exists = LeadStatus::query()->whereKey($statusId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'status_id' => 'Invalid lead status.',
            ]);
        }
    }

    private function assertLeadSource(int $sourceId): void
    {
        $exists = LeadSource::query()->whereKey($sourceId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'lead_source_id' => 'Invalid lead source.',
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
