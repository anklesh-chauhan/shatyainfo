<?php

namespace App\Services\Marketing;

use App\Enums\PromotionalSchemeAppliesTo;
use App\Models\AccountMaster;
use App\Models\ItemMaster;
use App\Models\PromotionalScheme;
use App\Models\PromotionalSchemeBenefit;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class PromotionalSchemeRegister
{
    private const FIELDS = ['code', 'name', 'scheme_type', 'status', 'applies_to', 'applies_to_id', 'valid_from', 'valid_to', 'min_order_value', 'description'];

    private const BENEFIT_FIELDS = ['benefit_type', 'item_master_id', 'buy_quantity', 'get_quantity', 'discount_value', 'min_quantity', 'max_quantity', 'remarks'];

    private const CATALOGS = [
        'customer' => [AccountMaster::class, 'name'],
        'territory' => [Territory::class, 'name'],
        'item' => [ItemMaster::class, 'item_name'],
    ];

    public function visible(User $actor, ?Builder $query = null): Builder
    {
        return ($query ?? PromotionalScheme::query())->applyVisibility('PromotionalScheme', $actor);
    }

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorizeRegister($actor);
        $query = $this->visible($actor);
        match ($filters['trash'] ?? 'active') {
            'all' => $query->withTrashed(),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };
        $search = trim((string) ($filters['search'] ?? ''));

        return $query->when($search !== '', fn (Builder $query): Builder => $query->where(
            fn (Builder $match): Builder => $match->whereLike('name', '%'.$search.'%')->orWhereLike('code', '%'.$search.'%')
        ))->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->latest('id')->paginate(30)->through(fn (PromotionalScheme $scheme): array => $this->summary($actor, $scheme));
    }

    public function find(User $actor, int $id): PromotionalScheme
    {
        $this->authorizeRegister($actor);
        $scheme = $this->visible($actor)->withTrashed()->findOrFail($id);
        abort_unless($actor->can('view', $scheme), 403);

        return $scheme;
    }

    public function catalog(User $actor, string $type, bool $existing = false): Builder
    {
        [$model] = self::CATALOGS[$type];
        $name = class_basename($model);
        $query = $model::query()->applyVisibility($name, $actor);
        if (! $actor->can(["ViewAny:{$name}", "View:{$name}"])) {
            return $query->whereKey([]);
        }
        if ($existing) {
            return $query->withTrashed();
        }

        return $type === 'item' ? $query->whereNull('parent_id') : $query;
    }

    public function options(User $actor, string $type, string $search): LengthAwarePaginator
    {
        [, $label] = self::CATALOGS[$type];
        $search = trim($search);

        return $this->catalog($actor, $type)
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match->whereLike($label, '%'.$search.'%')
                    ->when($type === 'item', fn (Builder $item): Builder => $item->orWhereLike('item_code', '%'.$search.'%'))
            ))->orderBy($label)->orderBy('id')->paginate(30, ['id', $label.' as label']);
    }

    public function detail(User $actor, PromotionalScheme $scheme): array
    {
        $this->loadBenefits($scheme);
        $visible = $this->visibleConfiguration($actor, $scheme);

        return [
            ...$this->summary($actor, $scheme),
            'revision' => $this->revision($scheme),
            'target' => $visible['target'],
            'min_order_value' => $scheme->min_order_value,
            'description' => $scheme->description,
            'can_update' => ! $scheme->trashed() && $visible['complete'] && $actor->can('update', $scheme),
            'benefits' => $scheme->benefits->filter(fn (PromotionalSchemeBenefit $benefit): bool => $benefit->item_master_id === null
                || isset($visible['items'][$benefit->item_master_id]))
                ->map(fn (PromotionalSchemeBenefit $benefit): array => [
                    ...$benefit->only(['id', ...self::BENEFIT_FIELDS]),
                    'item_name' => $visible['items'][$benefit->item_master_id] ?? null,
                ])->values()->all(),
        ];
    }

    public function save(User $actor, ?int $id, array $input): PromotionalScheme
    {
        $this->authorizeRegister($actor);
        try {
            return (new PromotionalScheme)->getConnection()->transaction(function () use ($actor, $id, $input): PromotionalScheme {
                if ($id === null) {
                    abort_unless($actor->can('create', PromotionalScheme::class), 403);
                    $scheme = new PromotionalScheme;
                } else {
                    $scheme = $this->visible($actor)->lockForUpdate()->findOrFail($id);
                    abort_unless($actor->can('view', $scheme) && $actor->can('update', $scheme), 403);
                }
                $this->loadBenefits($scheme, lock: $scheme->exists);
                if ($scheme->exists) {
                    abort_unless($this->visibleConfiguration($actor, $scheme)['complete'], 403);
                    abort_unless(hash_equals($this->revision($scheme), $input['revision']), 409, 'The scheme changed. Reload it before saving.');
                }

                $this->validateConfiguration($actor, $scheme, $input);
                $scheme->fill(Arr::only($input, self::FIELDS) + [
                    'applies_to_id' => null, 'valid_from' => null, 'valid_to' => null, 'min_order_value' => null, 'description' => null,
                ]);
                abort_unless($scheme->save(), 409, 'Could not save the scheme.');
                $this->saveBenefits($scheme, $input['benefits']);

                return $scheme->unsetRelations();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'This scheme code is already in use.']);
        }
    }

    /** @param list<int> $ids */
    public function changeRecords(User $actor, array $ids, string $action): int
    {
        $this->authorizeRegister($actor);
        $ability = match ($action) {
            'delete' => 'delete',
            'restore' => 'restore',
            'force-delete' => 'forceDelete',
        };

        return (new PromotionalScheme)->getConnection()->transaction(function () use ($actor, $ids, $ability): int {
            $records = $this->visible($actor)->withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $record) {
                abort_unless($actor->can($ability, $record), 403);
                abort_unless($record->trashed() === ($ability !== 'delete'), 409, 'A selected scheme changed. Reload your selection.');
                abort_if($ability === 'forceDelete' && $record->campaigns()->withTrashed()->exists(), 409, 'Schemes linked to marketing campaigns must be retained.');
                abort_unless($record->{$ability}(), 409, 'Could not update the selected schemes.');
            }

            return $records->count();
        }, attempts: 3);
    }

    private function authorizeRegister(User $actor): void
    {
        abort_unless($actor->can('viewAny', PromotionalScheme::class), 403);
    }

    private function summary(User $actor, PromotionalScheme $scheme): array
    {
        return [
            ...$scheme->only(['id', 'code', 'name']),
            'scheme_type' => $scheme->scheme_type->value,
            'status' => $scheme->status->value,
            'applies_to' => $scheme->applies_to->value,
            'valid_from' => $scheme->valid_from?->toDateString(),
            'valid_to' => $scheme->valid_to?->toDateString(),
            'trashed' => $scheme->trashed(),
            'can_view' => $actor->can('view', $scheme),
            'can_delete' => ! $scheme->trashed() && $actor->can('delete', $scheme),
            'can_restore' => $scheme->trashed() && $actor->can('restore', $scheme),
            'can_force_delete' => $scheme->trashed() && $actor->can('forceDelete', $scheme),
        ];
    }

    private function loadBenefits(PromotionalScheme $scheme, bool $lock = false): void
    {
        $scheme->setRelation('benefits', $scheme->benefits()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get());
    }

    private function visibleConfiguration(User $actor, PromotionalScheme $scheme): array
    {
        $ids = $scheme->benefits->pluck('item_master_id')->filter()->unique()->values()->all();
        $items = $this->catalog($actor, 'item', true)->whereKey($ids)->pluck('item_name', 'id');
        $target = null;
        $targetVisible = true;
        $type = $scheme->applies_to?->value;
        if (isset(self::CATALOGS[$type]) && $scheme->applies_to_id !== null) {
            [$model, $label] = self::CATALOGS[$type];
            $target = $this->catalog($actor, $type, true)->whereKey($scheme->applies_to_id)->first(['id', $label.' as label']);
            $targetVisible = $target !== null || ! $model::withTrashed()->whereKey($scheme->applies_to_id)->exists();
        }

        return ['items' => $items, 'target' => $target, 'complete' => $targetVisible && count($ids) === $items->count()];
    }

    private function revision(PromotionalScheme $scheme): string
    {
        return hash('sha256', json_encode([
            $scheme->only(self::FIELDS),
            $scheme->benefits->map->only(['id', ...self::BENEFIT_FIELDS])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function validateConfiguration(User $actor, PromotionalScheme $scheme, array $input): void
    {
        $from = $input['valid_from'] ?? null;
        $to = $input['valid_to'] ?? null;
        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages(['valid_to' => 'Choose an end date on or after the start date.']);
        }

        $target = $input['applies_to_id'] ?? null;
        $type = $input['applies_to'] instanceof PromotionalSchemeAppliesTo ? $input['applies_to']->value : $input['applies_to'];
        if ($target !== null && ($type !== $scheme->applies_to?->value || (int) $target !== (int) $scheme->applies_to_id)
            && ! $this->catalog($actor, $type)->whereKey((int) $target)->exists()) {
            throw ValidationException::withMessages(['applies_to_id' => 'Choose an available target.']);
        }

        $existing = $scheme->benefits->keyBy('id');
        $newItems = [];
        foreach ($input['benefits'] as $index => $benefit) {
            $id = $benefit['id'] ?? null;
            $item = $benefit['item_master_id'] ?? null;
            if ($id !== null && ! $existing->has((int) $id)) {
                throw ValidationException::withMessages(["benefits.{$index}.id" => 'This benefit does not belong to the scheme.']);
            }
            if ($item !== null && (int) $item !== (int) $existing->get((int) $id)?->item_master_id) {
                $newItems[] = (int) $item;
            }
            $min = $benefit['min_quantity'] ?? null;
            $max = $benefit['max_quantity'] ?? null;
            if ($min !== null && $max !== null && (float) $min > (float) $max) {
                throw ValidationException::withMessages(["benefits.{$index}.max_quantity" => 'Maximum quantity must be at least the minimum quantity.']);
            }
        }
        $newItems = array_unique($newItems);
        if ($newItems !== [] && $this->catalog($actor, 'item')->whereKey($newItems)->count() !== count($newItems)) {
            throw ValidationException::withMessages(['benefits' => 'Choose available benefit items.']);
        }
    }

    private function saveBenefits(PromotionalScheme $scheme, array $benefits): void
    {
        $existing = $scheme->benefits->keyBy('id');
        $kept = [];
        foreach ($benefits as $input) {
            $benefit = $existing->get((int) ($input['id'] ?? 0)) ?? $scheme->benefits()->make();
            $benefit->fill(Arr::only($input, self::BENEFIT_FIELDS) + array_fill_keys(self::BENEFIT_FIELDS, null))->save();
            $kept[] = $benefit->id;
        }
        $scheme->benefits()->whereKeyNot($kept)->delete();
    }
}
