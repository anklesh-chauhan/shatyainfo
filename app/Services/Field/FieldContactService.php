<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyMaster;
use App\Models\ContactDetail;
use App\Models\Deal;
use App\Models\Department;
use App\Models\Designation;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class FieldContactService
{
    /**
     * @return LengthAwarePaginator<int, ContactDetail>
     */
    public function list(User $user, ?string $search = null, int $perPage = 50, bool $register = false, ?int $companyId = null): LengthAwarePaginator
    {
        abort_unless($user->can('viewAny', ContactDetail::class), 403);

        $query = $this->visible($user)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id');

        if ($register) {
            $query->with(['approval', 'company' => $this->visibleOption($user, 'companies')]);
            if ($companyId !== null) {
                abort_unless($this->optionQuery($user, 'companies')->whereKey($companyId)->exists(), 404);
                $query->where('company_id', $companyId);
            }
        }

        if (filled($search)) {
            $term = '%'.$search.'%';
            $query->where(function (Builder $builder) use ($term, $register): void {
                $builder->whereLike('first_name', $term)
                    ->orWhereLike('last_name', $term)
                    ->orWhereLike('mobile_number', $term);
                if ($register) {
                    $builder->orWhereLike('email', $term)->orWhereLike('alternate_phone', $term)
                        ->orWhereRaw("concat(first_name, ' ', last_name) ilike ?", [$term]);
                }
            });
        }

        return $query->paginate($perPage > 0 ? min($perPage, 100) : 50);
    }

    private function visible(User $actor): Builder
    {
        return ContactDetail::query()->applyVisibility('ContactDetail', $actor);
    }

    public function find(User $actor, int $id, bool $lock = false): ContactDetail
    {
        abort_unless($actor->can('viewAny', ContactDetail::class), 403);
        $record = $this->visible($actor)->with('approval')->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
        abort_unless($actor->can('view', $record), 403);

        return $record;
    }

    public function detail(User $actor, ContactDetail $contact): ContactDetail
    {
        return $contact->load([
            'company' => $this->visibleOption($actor, 'companies'),
            'designation' => $this->visibleOption($actor, 'designations'),
            'department' => $this->visibleOption($actor, 'departments'),
            'addresses' => fn ($addresses) => $addresses->with(FieldAddressPayload::RELATIONS)->orderBy('sort')->orderBy('id'),
            'approval',
        ]);
    }

    /** @return Collection<int, Address> */
    public function addresses(User $actor, int $contactId): Collection
    {
        $contact = $this->find($actor, $contactId);

        return Address::query()
            ->where(fn (Builder $query): Builder => $query->where('contact_detail_id', $contact->id)
                ->orWhereMorphedTo('addressable', $contact))
            ->with(FieldAddressPayload::RELATIONS)
            ->orderBy('sort')->orderBy('id')->get();
    }

    private function visibleOption(User $actor, string $kind): \Closure
    {
        $visible = $this->optionQuery($actor, $kind);

        return fn ($reference) => $reference->whereIn($visible->getModel()->getQualifiedKeyName(), $visible->select('id'));
    }

    public function canWrite(User $actor, ContactDetail $contact, string $action): bool
    {
        return $actor->can(['view', $action], $contact) && ! $this->isLocked($actor, $contact);
    }

    public function assertCanWrite(User $actor, ContactDetail $contact, string $action): void
    {
        abort_unless($actor->can(['view', $action], $contact), 403);
        abort_if($this->isLocked($actor, $contact), 422, 'This contact is locked by approval.');
    }

    private function isLocked(User $actor, ContactDetail $contact): bool
    {
        $states = [$contact->approval_status, $contact->approval?->approval_status];

        return in_array('pending', $states, true) || (in_array('approved', $states, true) && $actor->cannot('overrideApproval', $contact));
    }

    public function optionAccess(User $actor, string $kind): bool
    {
        $model = $this->optionModel($kind);

        return $actor->can(['ViewAny:'.class_basename($model), 'View:'.class_basename($model)]);
    }

    private function optionModel(string $kind): string
    {
        return match ($kind) {
            'companies' => Company::class,
            'designations' => Designation::class,
            'departments' => Department::class,
        };
    }

    private function optionQuery(User $actor, string $kind): Builder
    {
        $model = $this->optionModel($kind);

        return $model::query()->applyVisibility(class_basename($model), $actor)
            ->when(! $this->optionAccess($actor, $kind), fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }

    public function options(User $actor, string $kind, string $search): LengthAwarePaginator
    {
        abort_unless($actor->can('viewAny', ContactDetail::class) && $this->optionAccess($actor, $kind), 403);

        return $this->optionQuery($actor, $kind)->when(filled($search), fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(25, ['id', 'name']);
    }

    public function canCreateOption(User $actor, string $kind): bool
    {
        return $kind !== 'companies' && $this->optionAccess($actor, $kind) && $actor->can('create', $this->optionModel($kind));
    }

    public function createOption(User $actor, string $kind, string $name): array
    {
        abort_unless($actor->can('viewAny', ContactDetail::class) && $this->canCreateOption($actor, $kind)
            && ($actor->can('Create:ContactDetail') || $actor->can('Update:ContactDetail')), 403);
        $model = $this->optionModel($kind);
        $record = $model::query()->create(['name' => $name]);

        return ['id' => $record->id, 'label' => $record->name];
    }

    public function save(User $actor, array $data, ?int $id = null): ContactDetail
    {
        return $actor->getConnection()->transaction(function () use ($actor, $data, $id): ContactDetail {
            if ($id === null) {
                abort_unless($actor->can('viewAny', ContactDetail::class) && $actor->can('create', ContactDetail::class), 403);
                $contact = new ContactDetail;
            } else {
                $contact = $this->writable($actor, $id);
            }
            foreach (['company_id' => 'companies', 'department_id' => 'departments', 'designation_id' => 'designations'] as $field => $kind) {
                if (! isset($data[$field]) || (int) $data[$field] === (int) $contact->getAttribute($field)) {
                    continue;
                }
                if (! $this->optionQuery($actor, $kind)->whereKey($data[$field])->when($kind === 'companies', fn (Builder $query): Builder => $query->lockForUpdate())->first(['id'])) {
                    throw ValidationException::withMessages([$field => 'Choose a visible option.']);
                }
            }
            if ($id === null && ! array_key_exists('whatsapp_number', $data)) {
                $data['whatsapp_number'] = $data['mobile_number'];
            }
            $contact->fill($data)->save();

            return $this->detail($actor, $contact);
        }, attempts: 3);
    }

    private function writable(User $actor, int $id): ContactDetail
    {
        $contact = $this->find($actor, $id, lock: true);
        $this->assertCanWrite($actor, $contact, 'update');

        return $contact;
    }

    public function delete(User $actor, array $ids): int
    {
        abort_unless($actor->can('viewAny', ContactDetail::class), 403);

        return $actor->getConnection()->transaction(function () use ($actor, $ids): int {
            $records = $this->visible($actor)->whereKey($ids)->with('approval')->orderBy('id')->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $contact) {
                $this->assertCanWrite($actor, $contact, 'delete');
                $this->assertNoSharedLinks($contact);
            }
            foreach ($records as $contact) {
                abort_unless($contact->delete(), 409);
            }

            return $records->count();
        }, attempts: 3);
    }

    public function assertNoSharedLinks(ContactDetail $contact, ?int $exceptAccountId = null): void
    {
        abort_if(($contact->account_master_id && (int) $contact->account_master_id !== $exceptAccountId)
            || $contact->contactable_id
            || $contact->accountMasters()->withTrashed()->when($exceptAccountId, fn ($query) => $query->whereKeyNot($exceptAccountId))->exists()
            || $contact->companyMasters()->withTrashed()->exists()
            || AccountMaster::withTrashed()->where('ref_dealer_contact', $contact->id)->exists()
            || CompanyMaster::withTrashed()->whereAny(['contact_details_id', 'dealer_name_id'], '=', $contact->id)->exists()
            || FollowUp::withTrashed()->where('to_whom', $contact->id)->exists()
            || Address::withTrashed()->where('contact_detail_id', $contact->id)->exists()
            || Lead::withTrashed()->where('contact_detail_id', $contact->id)->exists()
            || Deal::withTrashed()->where('contact_detail_id', $contact->id)->exists(), 422, 'This contact is linked to other records. Remove those links before deleting it.');
    }

    public function changeAddress(User $actor, int $contactId, ?int $addressId, ?array $data): ?Address
    {
        return $actor->getConnection()->transaction(function () use ($actor, $contactId, $addressId, $data): ?Address {
            $contact = $this->writable($actor, $contactId);

            return app(FieldAddressPayload::class)->change($actor, $contact->addresses(), $addressId, $data);
        }, attempts: 3);
    }
}
