<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyMaster;
use App\Models\ContactDetail;
use App\Models\GstPan;
use App\Models\IndustryType;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class FieldCompanyService
{
    public function __construct(private FieldAddressPayload $addresses) {}

    private function visible(User $actor): Builder
    {
        return Company::query()->applyVisibility('Company', $actor);
    }

    public function list(User $actor, string $search, int $perPage): LengthAwarePaginator
    {
        abort_unless($actor->can('viewAny', Company::class), 403);
        $search = '%'.$search.'%';

        return $this->visible($actor)->with('approval')
            ->when($search !== '%%', fn (Builder $companies): Builder => $companies->where(function (Builder $matches) use ($search): void {
                $matches->whereLike('name', $search);
                foreach (['phone_number', 'email', 'secondary_email', 'website', 'no_of_employees', 'twitter', 'linked_in'] as $field) {
                    $matches->orWhereLike($field, $search);
                }
            }))->orderBy('name')->orderBy('id')->paginate($perPage);
    }

    private function find(User $actor, int $id, bool $lock = false): Company
    {
        abort_unless($actor->can('viewAny', Company::class), 403);
        $company = $this->visible($actor)->with('approval')->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
        abort_unless($actor->can('view', $company), 403);

        return $company;
    }

    public function show(User $actor, int $id): Company
    {
        return $this->detail($actor, $this->find($actor, $id));
    }

    private function detail(User $actor, Company $company): Company
    {
        $accounts = AccountMaster::query()->applyVisibility('AccountMaster', $actor)
            ->when(! $actor->can(['ViewAny:AccountMaster', 'View:AccountMaster']), fn (Builder $query): Builder => $query->whereRaw('1 = 0'));

        return $company->load([
            'approval',
            'addresses' => fn ($addresses) => $addresses->with(FieldAddressPayload::RELATIONS)->orderBy('sort')->orderBy('id'),
            'industryType' => fn ($industry) => $industry->whereIn('industry_types.id', $this->industries($actor)->select('industry_types.id')),
            'accountMaster' => fn ($account) => $account->whereIn('account_masters.id', $accounts->select('account_masters.id')),
        ]);
    }

    public function canChooseIndustry(User $actor): bool
    {
        return $actor->can(['ViewAny:IndustryType', 'View:IndustryType']);
    }

    private function industries(User $actor): Builder
    {
        return IndustryType::query()->applyVisibility('IndustryType', $actor)
            ->when(! $this->canChooseIndustry($actor), fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }

    public function industryOptions(User $actor, string $search): LengthAwarePaginator
    {
        abort_unless($actor->can('viewAny', Company::class) && $this->canChooseIndustry($actor), 403);

        return $this->industries($actor)->when(filled($search), fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(25, ['id', 'name']);
    }

    public function canCreateForContact(User $actor): bool
    {
        return $actor->can(['ViewAny:ContactDetail', 'ViewAny:Company', 'View:Company', 'Create:Company'])
            && ($actor->can('Create:ContactDetail') || $actor->can('Update:ContactDetail'));
    }

    public function createForContact(User $actor, array $data): Company
    {
        abort_unless($this->canCreateForContact($actor), 403);

        return $this->save($actor, $data);
    }

    public function save(User $actor, array $data, ?int $id = null): Company
    {
        return $actor->getConnection()->transaction(function () use ($actor, $data, $id): Company {
            if ($id === null) {
                abort_unless($actor->can('viewAny', Company::class) && $actor->can('create', Company::class), 403);
                $company = new Company;
            } else {
                $company = $this->writable($actor, $id);
            }
            if (isset($data['industry_type_id']) && (int) $data['industry_type_id'] !== (int) $company->industry_type_id
                && ! $this->industries($actor)->whereKey($data['industry_type_id'])->exists()) {
                throw ValidationException::withMessages(['industry_type_id' => 'Choose an industry type you can access.']);
            }
            $company->fill($data)->save();

            return $this->detail($actor, $company);
        }, attempts: 3);
    }

    public function isLocked(User $actor, Company $company): bool
    {
        $states = [$company->approval_status, $company->approval?->approval_status];

        return in_array('pending', $states, true) || (in_array('approved', $states, true) && $actor->cannot('overrideApproval', $company));
    }

    private function assertUnlocked(User $actor, Company $company): void
    {
        abort_if($this->isLocked($actor, $company), 422, 'This company is locked by approval.');
    }

    private function writable(User $actor, int $id): Company
    {
        $company = $this->find($actor, $id, lock: true);
        abort_unless($actor->can('update', $company), 403);
        $this->assertUnlocked($actor, $company);

        return $company;
    }

    public function delete(User $actor, array $ids): int
    {
        abort_unless($actor->can('viewAny', Company::class), 403);

        return $actor->getConnection()->transaction(function () use ($actor, $ids): int {
            $companies = $this->visible($actor)->whereKey($ids)->with('approval')->orderBy('id')->lockForUpdate()->get();
            abort_unless($companies->count() === count($ids), 404);
            foreach ($companies as $company) {
                abort_unless($actor->can(['view', 'delete'], $company), 403);
                $this->assertUnlocked($actor, $company);
                $this->assertNoExternalLinks($company);
            }
            foreach ($companies as $company) {
                abort_unless($company->delete(), 409);
            }

            return $companies->count();
        }, attempts: 3);
    }

    private function assertNoExternalLinks(Company $company): void
    {
        abort_if($company->account_master_id
            || ContactDetail::withTrashed()->where(fn (Builder $contacts): Builder => $contacts->where('company_id', $company->id)
                ->orWhere(fn (Builder $parents): Builder => $parents->where('contactable_type', $company->getMorphClass())->where('contactable_id', $company->id)))->exists()
            || CompanyMaster::withTrashed()->where('company_id', $company->id)->exists()
            || GstPan::withoutGlobalScopes()->where('company_id', $company->id)->exists()
            || Address::withTrashed()->where('company_id', $company->id)->where(fn (Builder $addresses): Builder => $addresses
                ->whereNull('addressable_type')->orWhereNull('addressable_id')
                ->orWhere('addressable_type', '!=', $company->getMorphClass())->orWhere('addressable_id', '!=', $company->id))->exists(),
            422, 'This company is linked to other records. Remove those links before deleting it.');
    }

    public function changeAddress(User $actor, int $companyId, ?int $addressId, ?array $data): ?Address
    {
        return $actor->getConnection()->transaction(function () use ($actor, $companyId, $addressId, $data): ?Address {
            $company = $this->writable($actor, $companyId);
            if ($addressId === null && $data !== null) {
                $data['sort'] = (int) $company->addresses()->max('sort') + 1;
            }
            if ($data === null) {
                $company->addresses()->lockForUpdate()->findOrFail($addressId, ['id']);
                abort_if(GstPan::withTrashed()->where('address_id', $addressId)->exists(), 422, 'This address is used by tax records.');
            }

            return $this->addresses->change($actor, $company->addresses(), $addressId, $data, addressType: 'Company');
        }, attempts: 3);
    }

    public function reorderAddresses(User $actor, int $id, array $ids): void
    {
        $actor->getConnection()->transaction(function () use ($actor, $id, $ids): void {
            $company = $this->writable($actor, $id);
            $addresses = $company->addresses()->with('approval')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($addresses->keys()->sort()->values()->all() === collect($ids)->sort()->values()->all(), 409, 'The address list changed. Refresh the company.');
            foreach ($ids as $index => $addressId) {
                $address = $addresses->get($addressId);
                $this->addresses->assertEditable($actor, $address);
                $address->update(['sort' => $index + 1]);
            }
        }, attempts: 3);
    }
}
