<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\AccountMasterPatch;
use App\Models\FlexiblePlanEntry;
use App\Models\Patch;
use App\Models\SalesTourPlanDetail;
use App\Models\StandardFareChart;
use App\Models\Territory;
use App\Models\TravelSegment;
use App\Models\TypeMaster;
use App\Models\User;
use App\Models\Visit;
use App\Services\PositionService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FieldPatchService
{
    public function __construct(private FieldTerritoryService $territories) {}

    public function assignableTerritories(User $actor): Builder
    {
        $query = Territory::query();
        if ($actor->hasAnyRole(['super_admin', 'administration_admin']) || $actor->can('AccessAllRecords')) {
            return $query;
        }
        $positionIds = PositionService::getTerritoryIdsForUser($actor);
        if ($positionIds !== [] || $actor->can('ViewOwnTerritory:Patch')) {
            return $query->whereKey($positionIds);
        }
        $divisions = $actor->employee?->employmentDetail?->organizationalUnits()->pluck('organizational_units.id') ?? collect();

        return $query->whereHas('divisions', fn (Builder $links): Builder => $links->whereKey($divisions->all()));
    }

    public function territoryOptions(User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorizeRegister($actor);

        return $this->search($this->assignableTerritories($actor), $filters, ['name', 'code'])->orderBy('name')->orderBy('id')->paginate(25);
    }

    public function pinOptions(User $actor, int $id, array $filters): LengthAwarePaginator
    {
        $this->authorizeRegister($actor);
        $territory = $this->assignableTerritories($actor)->findOrFail($id);

        return $territory->cityPinCodes()
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->searchLocation($filters['search']))
            ->orderBy('pin_code')->orderBy('city_pin_codes.id')->paginate(25);
    }

    private function authorizeRegister(User $actor): void
    {
        abort_unless($actor->can('viewAny', Patch::class), 403);
    }

    public function visible(User $actor): Builder
    {
        return Patch::query()->applyVisibility('Patch', $actor);
    }

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorizeRegister($actor);
        $query = $this->visible($actor);
        match ($filters['trash'] ?? 'current') {
            'all' => $query->withTrashed(),
            'trashed' => $query->onlyTrashed(),
            default => null,
        };
        if (isset($filters['territory_id'])) {
            $query->where('territory_id', $filters['territory_id'])->whereIn('territory_id', $this->assignableTerritories($actor)->select('id'));
        }
        if (filled($filters['search'] ?? null)) {
            $pattern = '%'.$filters['search'].'%';
            $query->where(fn (Builder $matches): Builder => $matches->whereLike('name', $pattern)->orWhereLike('code', $pattern)
                ->orWhereIn('territory_id', $this->assignableTerritories($actor)->whereLike('name', $pattern)->select('id')));
        }

        return $query->with(['approval', 'territory.approval', 'cityPinCode.city'])
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 25);
    }

    public function find(User $actor, int $id, bool $lock = false, bool $trashed = false): Patch
    {
        $this->authorizeRegister($actor);
        $patch = $this->visible($actor)->with(['approval', 'territory.approval', 'cityPinCode.city'])
            ->when($trashed, fn (Builder $query): Builder => $query->withTrashed())
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
        abort_unless($actor->can('view', $patch), 403);

        return $patch;
    }

    public function capabilities(User $actor, Patch $patch, Collection $assignableIds): array
    {
        $canView = $actor->can('view', $patch);
        $canChange = $canView && ! $patch->trashed() && ! $this->territories->locked($actor, $patch);
        $canEdit = $canChange && $patch->territory && $assignableIds->contains($patch->territory_id)
            && ! $this->territories->locked($actor, $patch->territory) && $actor->can('update', $patch);
        $canViewCustomers = $canView && $actor->can(['ViewAny:AccountMaster', 'View:AccountMaster']);

        return [
            'can_view' => $canView,
            'can_edit' => $canEdit,
            'can_delete' => $canChange && $actor->can('delete', $patch),
            'can_view_customers' => $canViewCustomers,
            'can_manage_customers' => $canViewCustomers && $canEdit && $actor->can('Update:AccountMaster'),
        ];
    }

    public function save(User $actor, array $data, ?int $id = null): Patch
    {
        $this->authorizeRegister($actor);
        try {
            return $actor->getConnection()->transaction(function () use ($actor, $data, $id): Patch {
                if ($id === null) {
                    abort_unless($actor->can('create', Patch::class), 403);
                    $patch = new Patch(['approval_status' => 'draft']);
                } else {
                    $patch = $this->find($actor, $id, lock: true);
                    abort_unless($actor->can('update', $patch), 403);
                    abort_if($this->territories->locked($actor, $patch), 422, 'This patch is locked by approval.');
                }
                $territoryId = (int) ($data['territory_id'] ?? $patch->territory_id);
                $territories = $this->assignableTerritories($actor)->whereKey(array_values(array_filter(array_unique([$territoryId, $patch->territory_id]))))
                    ->with('approval')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $territory = $territories->get($territoryId);
                if (! $territory || ($patch->exists && ! $territories->has($patch->territory_id))) {
                    throw ValidationException::withMessages(['territory_id' => 'Choose a territory within your assignments.']);
                }
                foreach ($territories as $related) {
                    abort_if($this->territories->locked($actor, $related), 422, 'A territory is locked by approval.');
                }
                $moving = $patch->exists && $territoryId !== (int) $patch->territory_id;
                $changingPin = array_key_exists('city_pin_code_id', $data) && ($data['city_pin_code_id'] === null
                    ? $patch->city_pin_code_id !== null
                    : (int) $data['city_pin_code_id'] !== (int) $patch->city_pin_code_id);
                $pin = array_key_exists('city_pin_code_id', $data) ? $data['city_pin_code_id'] : $patch->city_pin_code_id;
                if ($pin !== null && ($moving || $changingPin) && ! $territory->cityPinCodes()->whereKey($pin)->exists()) {
                    throw ValidationException::withMessages(['city_pin_code_id' => 'Choose a city or PIN linked to the selected territory.']);
                }
                if ($moving || $changingPin) {
                    $this->validateRouteChange($patch, $territoryId, $pin, $moving);
                }
                foreach (['name', 'code'] as $field) {
                    if (isset($data[$field]) && $data[$field] !== $patch->{$field}
                        && Patch::withTrashed()->whereKeyNot($patch->id ?? 0)->where($field, $data[$field])->exists()) {
                        throw ValidationException::withMessages([$field => 'This '.$field.' is already in use.']);
                    }
                }
                $patch->fill($data)->save();

                return $patch->load(['approval', 'territory.approval', 'cityPinCode.city']);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'The patch name or code is already in use.']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['territory_id' => $exception->getMessage()]);
        }
    }

    public function validateRouteChange(Patch $patch, int $territoryId, ?int $pin, bool $moving): void
    {
        if (! $patch->exists) {
            return;
        }
        if ($moving && $this->hasFieldLinks([$patch->id])) {
            throw ValidationException::withMessages(['territory_id' => 'This patch is already used by field records.']);
        }
        $proposed = new Patch(['territory_id' => $territoryId, 'city_pin_code_id' => $pin]);
        $eligible = $proposed->eligibleCustomers(AccountMaster::query())->select('account_masters.id');
        if ($patch->companies()->whereNotIn('account_masters.id', $eligible)->exists()) {
            throw ValidationException::withMessages(['city_pin_code_id' => 'Remove customer assignments outside the new route before changing it.']);
        }
    }

    public function trash(User $actor, array $ids): int
    {
        $this->authorizeRegister($actor);

        return $actor->getConnection()->transaction(function () use ($actor, $ids): int {
            $patches = $this->visible($actor)->whereKey($ids)->orderBy('id')->with('approval')->lockForUpdate()->get();
            abort_unless($patches->count() === count($ids), 404);
            foreach ($patches as $patch) {
                abort_unless($actor->can('view', $patch) && $actor->can('delete', $patch), 403);
                abort_if($this->territories->locked($actor, $patch), 422, 'A selected patch is locked by approval.');
            }
            $linked = AccountMasterPatch::query()->whereIn('patch_id', $ids)->exists() || $this->hasFieldLinks($ids);
            abort_if($linked, 422, 'A selected patch is linked to customer or field records.');
            foreach ($patches as $patch) {
                $patch->delete();
            }

            return $patches->count();
        }, attempts: 3);
    }

    private function hasFieldLinks(array $ids): bool
    {
        foreach ([Visit::class, FlexiblePlanEntry::class, StandardFareChart::class, TravelSegment::class] as $model) {
            if ($model::query()->whereIn('patch_id', $ids)->exists()) {
                return true;
            }
        }

        return SalesTourPlanDetail::query()->whereHas('patches', fn (Builder $query): Builder => $query->whereKey($ids))->exists();
    }

    public function visibleCustomers(User $actor): Builder
    {
        return AccountMaster::query()->applyVisibility('AccountMaster', $actor);
    }

    public function customers(User $actor, int $patchId, array $filters, bool $available): LengthAwarePaginator
    {
        $patch = $this->find($actor, $patchId, trashed: true);
        abort_unless($actor->can(['ViewAny:AccountMaster', 'View:AccountMaster']), 403);
        $visible = $this->visibleCustomers($actor);
        if (isset($filters['type_master_id'])) {
            $visible->where('type_master_id', $filters['type_master_id']);
        }
        $this->search($visible, $filters, ['name', 'account_code']);
        if ($available) {
            $this->authorizeCustomerChanges($actor, $patch);
            $assignedIds = $patch->companies()->allRelatedIds();

            return $patch->eligibleCustomers($visible)->whereKeyNot($assignedIds->all())
                ->orderBy('name')->orderBy('id')->paginate(25);
        }

        return $patch->companies()->whereIn('account_masters.id', $visible->select('account_masters.id'))
            ->with(['typeMaster.parent', 'addresses.areaTown'])->orderBy('account_masters.id')->paginate(25);
    }

    public function lockAssignments(User $actor, Patch $patch): void
    {
        $territory = $this->assignableTerritories($actor)->with('approval')->lockForUpdate()->find($patch->territory_id);
        abort_unless($territory, 403);
        $patch->setRelation('territory', $territory);
        $this->authorizeCustomerChanges($actor, $patch);
    }

    public function customerTypes(User $actor, int $patchId, array $filters): LengthAwarePaginator
    {
        $patch = $this->find($actor, $patchId, trashed: true);
        abort_unless($actor->can(['ViewAny:AccountMaster', 'View:AccountMaster']), 403);
        $visible = $this->visibleCustomers($actor)->select('account_masters.id');
        $used = $patch->companies()->whereIn('account_masters.id', $visible)->getQuery()->select('account_masters.type_master_id');
        $types = TypeMaster::query()->whereIn('id', $used->reorder());

        return $this->search($types, $filters, ['name'])->orderBy('name')->orderBy('id')->paginate(25);
    }

    private function authorizeCustomerChanges(User $actor, Patch $patch): void
    {
        abort_unless($actor->can(['ViewAny:AccountMaster', 'View:AccountMaster', 'Update:AccountMaster']) && $actor->can('update', $patch)
            && $this->assignableTerritories($actor)->whereKey($patch->territory_id)->exists(), 403);
        abort_if($patch->trashed() || $this->territories->locked($actor, $patch) || $this->territories->locked($actor, $patch->territory), 422, 'This patch or its territory is locked.');
    }

    public function changeCustomers(User $actor, int $patchId, array $data): int
    {
        return $actor->getConnection()->transaction(function () use ($actor, $patchId, $data): int {
            $patch = $this->find($actor, $patchId, lock: true);
            $this->lockAssignments($actor, $patch);
            $action = $data['action'];
            $ids = array_map('intval', $data['ids'] ?? []);
            $relation = $patch->companies();
            $assigned = $relation->allRelatedIds()->map(fn ($id): int => (int) $id)->all();
            $accounts = $this->visibleCustomers($actor);
            if (in_array($action, ['attach', 'attach_all'], true)) {
                $accounts = $patch->eligibleCustomers($accounts);
            }
            if ($action === 'attach_all') {
                $ids = $accounts->whereKeyNot($assigned)->orderBy('id')->lockForUpdate()->pluck('id')->all();
            } else {
                $abortMessage = in_array($action, ['detach', 'sequence'], true)
                    ? 'Select assigned customers you can access.' : 'Select eligible customers you can access.';
                if ($accounts->whereKey($ids)->orderBy('id')->lockForUpdate()->pluck('id')->count() !== count($ids)
                    || (in_array($action, ['detach', 'sequence'], true) && array_diff($ids, $assigned) !== [])) {
                    throw ValidationException::withMessages(['ids' => $abortMessage]);
                }
            }
            if ($action === 'sequence') {
                return $this->updateSequence($patch, $ids, $data['sequence_no']);
            }
            if ($action === 'detach') {
                return $relation->detach($ids);
            }
            $new = array_diff($ids, $assigned);
            foreach ($new as $id) {
                $relation->attach($id, ['created_by' => $actor->id, 'updated_by' => $actor->id]);
            }

            return count($new);
        }, attempts: 3);
    }

    private function updateSequence(Patch $patch, array $ids, mixed $sequence): int
    {
        if (count($ids) !== 1) {
            throw ValidationException::withMessages(['ids' => 'Select one customer to change its sequence.']);
        }
        if ($sequence !== null && AccountMasterPatch::query()->where('patch_id', $patch->id)
            ->where('sequence_no', $sequence)->where('account_master_id', '!=', $ids[0])->exists()) {
            throw ValidationException::withMessages(['sequence_no' => 'That sequence number is already assigned.']);
        }

        return $patch->companies()->updateExistingPivot($ids[0], ['sequence_no' => $sequence, 'updated_by' => auth()->id()]);
    }

    private function search(Builder $query, array $filters, array $columns): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $pattern = '%'.$filters['search'].'%';
            $query->where(function (Builder $matches) use ($columns, $pattern): void {
                foreach ($columns as $column) {
                    $matches->orWhereLike($column, $pattern);
                }
            });
        }

        return $query;
    }
}
