<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\BaseModel;
use App\Models\CityPinCode;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\Patch;
use App\Models\Position;
use App\Models\Region;
use App\Models\SalesDcr;
use App\Models\SalesTourPlanDetail;
use App\Models\Territory;
use App\Models\TerritorySalesAssignment;
use App\Models\TerritorySalesTarget;
use App\Models\TypeMaster;
use App\Models\User;
use App\Models\Visit;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class FieldTerritoryService
{
    public const OPTIONS = [
        'parents' => ['parent_territory_id', Territory::class],
        'types' => ['type_master_id', TypeMaster::class],
        'regions' => ['region_id', Region::class],
        'divisions' => ['division_ids', OrganizationalUnit::class],
        'positions' => ['position_ids', Position::class],
        'city-pin-codes' => ['city_pin_code_ids', CityPinCode::class],
    ];

    private const RELATIONSHIPS = [
        'parents' => 'parentTerritory',
        'types' => 'typeMaster',
        'regions' => 'region',
        'divisions' => 'divisions',
        'positions' => 'positions',
        'city-pin-codes' => 'cityPinCodes',
    ];

    private function authorizeRegister(User $actor): void
    {
        abort_unless($actor->can('viewAny', Territory::class), 403);
    }

    private function visible(User $actor): Builder
    {
        return Territory::query()->applyVisibility('Territory', $actor);
    }

    private function canChoose(User $actor, string $kind): bool
    {
        $model = class_basename(self::OPTIONS[$kind][1]);

        return $kind === 'city-pin-codes' || $actor->can(["ViewAny:{$model}", "View:{$model}"]);
    }

    public function capabilities(User $actor): array
    {
        return [
            'can_create' => $actor->can('create', Territory::class),
            'divisions_enabled' => Organization::divisionsEnabled(),
            'option_kinds' => array_values(array_filter(array_keys(self::OPTIONS), fn (string $kind): bool => $this->canChoose($actor, $kind))),
        ];
    }

    private function catalog(User $actor, string $kind): Builder
    {
        $model = self::OPTIONS[$kind][1];
        if ($kind === 'city-pin-codes') {
            return CityPinCode::query()->with('city');
        }

        $query = $model::query()->applyVisibility(class_basename($model), $actor);
        if (! $this->canChoose($actor, $kind)) {
            $query->whereRaw('1 = 0');
        }

        return match ($kind) {
            'divisions' => $query->whereHas('typeMaster', fn (Builder $types): Builder => $types->where('name', 'Division')),
            default => $query,
        };
    }

    public function options(User $actor, string $kind, string $search, ?int $territoryId): LengthAwarePaginator
    {
        $this->authorizeRegister($actor);
        abort_unless($this->canChoose($actor, $kind), 403);
        $query = $this->catalog($actor, $kind);

        if ($kind === 'parents' && $territoryId !== null) {
            $this->find($actor, $territoryId);
            $graph = Territory::withTrashed()->pluck('parent_territory_id', 'id')->all();
            $query->whereKeyNot($territoryId)->whereKeyNot(
                array_values(array_filter(array_keys($graph), fn (int $id): bool => $this->wouldCycle($graph, $id, $territoryId)))
            );
        }
        if ($kind === 'city-pin-codes') {
            return $query->when($search !== '', fn (Builder $query): Builder => $query->searchLocation($search))
                ->orderBy('pin_code')->orderBy('id')->paginate(25);
        }

        return $query->when($search !== '', fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(25);
    }

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorizeRegister($actor);
        $query = $this->visible($actor)->with('approval');
        match ($filters['trash'] ?? 'current') {
            'trashed' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => null,
        };
        foreach (['parent_territory_id' => 'parents', 'type_master_id' => 'types'] as $field => $kind) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field])
                    ->whereIn($field, $this->catalog($actor, $kind)->select('id'));
            }
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['search'] ?? null)) {
            $pattern = '%'.$filters['search'].'%';
            $query->where(function (Builder $matches) use ($actor, $pattern): void {
                $matches->whereLike('name', $pattern)->orWhereLike('code', $pattern)->orWhereLike('status', $pattern);
                foreach (['parents' => 'parent_territory_id', 'types' => 'type_master_id'] as $kind => $column) {
                    $matches->orWhereIn($column, $this->catalog($actor, $kind)->whereLike('name', $pattern)->select('id'));
                }
            });
        }

        return $this->loadLinks($actor, $query, ['parents', 'types'])->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 25);
    }

    private function loadLinks(User $actor, Builder|Territory $territory, array $kinds): Builder|Territory
    {
        $relations = [];
        foreach ($kinds as $kind) {
            $column = (new (self::OPTIONS[$kind][1]))->qualifyColumn('id');
            $relations[self::RELATIONSHIPS[$kind]] = fn ($relation) => $relation
                ->whereIn($column, $this->catalog($actor, $kind)->select($column))->orderBy($column);
        }

        return $territory instanceof Builder ? $territory->with($relations) : $territory->load($relations);
    }

    private function find(User $actor, int $id, bool $lock = false, bool $withTrashed = false): Territory
    {
        $this->authorizeRegister($actor);
        $territory = $this->visible($actor)->with('approval')
            ->when($withTrashed, fn (Builder $query): Builder => $query->withTrashed())
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
        abort_unless($actor->can('view', $territory), 403);

        return $territory;
    }

    public function show(User $actor, int $id): Territory
    {
        return $this->loadLinks($actor, $this->find($actor, $id, withTrashed: true), array_keys(self::OPTIONS));
    }

    public function locked(User $actor, BaseModel $record): bool
    {
        $legacyState = $record instanceof Patch ? $record->legacyApprovalState() : $record->approval_status;
        $states = [$legacyState, $record->approval?->approval_status];

        return in_array('pending', $states, true)
            || (in_array('approved', $states, true) && $actor->cannot('OverrideApproval:'.class_basename($record)));
    }

    public function save(User $actor, array $data, ?int $id = null): Territory
    {
        $this->authorizeRegister($actor);
        if ($id === null) {
            abort_unless($actor->can('create', Territory::class), 403);
        }

        return $actor->getConnection()->transaction(function () use ($actor, $data, $id): Territory {
            $graph = isset($data['parent_territory_id'])
                ? Territory::withTrashed()->orderBy('id')->lockForUpdate()->pluck('parent_territory_id', 'id')->all()
                : [];
            $territory = $id === null ? new Territory(['status' => 'active']) : $this->find($actor, $id, lock: true);
            if ($territory->exists) {
                abort_unless($actor->can('update', $territory), 403);
                abort_if($this->locked($actor, $territory), 422, 'This territory is locked by approval.');
            }
            $this->validateUnique($territory, $data);
            $this->validateReferences($actor, $territory, $data, $graph);
            $divisionsEnabled = Organization::divisionsEnabled();
            if ($divisionsEnabled && ! $territory->exists && empty($data['division_ids'])) {
                throw ValidationException::withMessages(['division_ids' => 'Select at least one division.']);
            }
            if (! $divisionsEnabled && array_key_exists('division_ids', $data)) {
                throw ValidationException::withMessages(['division_ids' => 'Division changes require divisions to be enabled in company setup.']);
            }

            $territory->fill(Arr::except($data, ['city_pin_code_ids', 'division_ids', 'position_ids']))->save();
            foreach (['divisions', 'positions', 'city-pin-codes'] as $kind) {
                $field = self::OPTIONS[$kind][0];
                if (array_key_exists($field, $data)) {
                    $this->syncLinks($actor, $territory, $kind, $data[$field]);
                }
            }

            return $this->loadLinks($actor, $territory->load('approval'), array_keys(self::OPTIONS));
        }, attempts: 3);
    }

    private function validateUnique(BaseModel $record, array $data): void
    {
        foreach (['name', 'code'] as $field) {
            if (isset($data[$field]) && $data[$field] !== $record->{$field}
                && $record->newQuery()->withTrashed()->where($field, $data[$field])->whereKeyNot($record->getKey() ?? 0)->exists()) {
                throw ValidationException::withMessages([$field => 'This '.$field.' is already in use.']);
            }
        }
    }

    private function validateReferences(User $actor, Territory $territory, array $data, array $graph): void
    {
        foreach (['parents', 'types', 'regions'] as $kind) {
            $field = self::OPTIONS[$kind][0];
            $selected = isset($data[$field]) ? (int) $data[$field] : null;
            if ($selected !== null && $selected !== (int) $territory->{$field} && ! $this->catalog($actor, $kind)->whereKey($selected)->exists()) {
                throw ValidationException::withMessages([$field => 'Choose an available option.']);
            }
        }
        if (isset($data['parent_territory_id']) && $this->wouldCycle($graph, (int) $data['parent_territory_id'], $territory->id)) {
            throw ValidationException::withMessages(['parent_territory_id' => 'Choose a parent outside this territory’s hierarchy.']);
        }
    }

    private function wouldCycle(array $graph, int $parentId, ?int $territoryId): bool
    {
        $seen = [];
        while ($parentId !== null) {
            if ($parentId === $territoryId || isset($seen[$parentId])) {
                return true;
            }
            $seen[$parentId] = true;
            $parentId = $graph[$parentId] ?? null;
        }

        return false;
    }

    private function syncLinks(User $actor, Territory $territory, string $kind, array $ids): void
    {
        $field = self::OPTIONS[$kind][0];
        abort_unless($this->canChoose($actor, $kind), 403);
        $relation = $territory->{self::RELATIONSHIPS[$kind]}();
        $current = $relation->allRelatedIds()->map(fn ($id): int => (int) $id)->all();
        $desired = array_map('intval', $ids);
        $visible = $this->catalog($actor, $kind)->whereKey(array_unique([...$current, ...$desired]))->pluck('id')->all();
        if (array_diff($desired, $current, $visible) !== []) {
            throw ValidationException::withMessages([$field => 'Choose only available options.']);
        }
        if ($kind === 'divisions' && $desired === []) {
            throw ValidationException::withMessages([$field => 'Select at least one division.']);
        }
        $relation->sync(array_unique([...$desired, ...array_diff($current, $visible)]));
    }

    public function delete(User $actor, array $ids): int
    {
        $this->authorizeRegister($actor);

        return $actor->getConnection()->transaction(function () use ($actor, $ids): int {
            $territories = $this->visible($actor)->whereKey($ids)->with('approval')->orderBy('id')->lockForUpdate()->get();
            abort_unless($territories->count() === count($ids), 404);
            foreach ($territories as $territory) {
                abort_unless($actor->can('view', $territory) && $actor->can('delete', $territory), 403);
                abort_if($this->locked($actor, $territory), 422, 'A selected territory is locked by approval.');
            }
            $linked = Territory::query()->whereKey($ids)->where(fn (Builder $query): Builder => $query
                ->whereHas('childTerritories', fn (Builder $children): Builder => $children->whereKeyNot($ids))
                ->orWhereHas('patches')->orWhereHas('positions')->orWhereHas('locations'))->exists();
            abort_if($linked || Position::query()->whereIn('hq_territory_id', $ids)->exists(), 422, 'A selected territory has active hierarchy or assignment links.');
            foreach ([AccountMaster::class, Visit::class, SalesDcr::class, SalesTourPlanDetail::class, TerritorySalesTarget::class, TerritorySalesAssignment::class] as $model) {
                abort_if($model::query()->whereIn('territory_id', $ids)->exists(), 422, 'A selected territory is used by field or sales records.');
            }
            foreach ($territories as $territory) {
                $territory->delete();
            }

            return $territories->count();
        }, attempts: 3);
    }

    public function patches(User $actor, int $territoryId, array $filters): LengthAwarePaginator
    {
        $territory = $this->find($actor, $territoryId, withTrashed: true);
        $query = $this->visiblePatches($actor, $territory);
        if (filled($filters['search'] ?? null)) {
            $pattern = '%'.$filters['search'].'%';
            $query->where(fn (Builder $matches): Builder => $matches->whereLike('name', $pattern)->orWhereLike('code', $pattern));
        }

        return $query->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 25)
            ->through(fn (Patch $patch): Patch => $patch->setRelation('territory', $territory));
    }

    private function visiblePatches(User $actor, Territory $territory): Builder
    {
        abort_unless($actor->can(['ViewAny:Patch', 'View:Patch']), 403);

        return Patch::query()->applyVisibility('Patch', $actor)->where('territory_id', $territory->id)->with(['approval', 'cityPinCode.city']);
    }

    public function savePatch(User $actor, int $territoryId, int $patchId, array $data): Patch
    {
        return $actor->getConnection()->transaction(function () use ($actor, $territoryId, $patchId, $data): Patch {
            $territory = $this->find($actor, $territoryId, lock: true);
            $patch = $this->visiblePatches($actor, $territory)->lockForUpdate()->findOrFail($patchId);
            abort_unless($actor->can('update', $patch), 403);
            abort_if($this->locked($actor, $territory) || $this->locked($actor, $patch), 422, 'This patch or its territory is locked by approval.');
            $this->validateUnique($patch, $data);
            if (isset($data['city_pin_code_id']) && (int) $data['city_pin_code_id'] !== $patch->city_pin_code_id
                && ! CityPinCode::query()->whereKey($data['city_pin_code_id'])->exists()) {
                throw ValidationException::withMessages(['city_pin_code_id' => 'Choose an available city or area.']);
            }
            if (array_key_exists('city_pin_code_id', $data) && $data['city_pin_code_id'] !== $patch->city_pin_code_id) {
                app(FieldPatchService::class)->validateRouteChange($patch, $territoryId, $data['city_pin_code_id'], false);
            }
            try {
                $patch->fill($data)->save();
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['territory_id' => $exception->getMessage()]);
            }

            return $patch->load('cityPinCode.city')->setRelation('territory', $territory);
        }, attempts: 3);
    }
}
