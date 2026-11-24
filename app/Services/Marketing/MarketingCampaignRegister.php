<?php

namespace App\Services\Marketing;

use App\Models\ItemMaster;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignItem;
use App\Models\MarketingCampaignTerritoryQuota;
use App\Models\NumberSeries;
use App\Models\PromotionalScheme;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class MarketingCampaignRegister
{
    private const FIELDS = ['name', 'status', 'promotional_scheme_id', 'start_date', 'end_date', 'total_budget', 'description'];

    private const ITEM_FIELDS = ['item_master_id', 'total_quota', 'unit_value'];

    private const QUOTA_FIELDS = ['territory_id', 'item_master_id', 'quota_quantity'];

    private const CATALOGS = [
        'items' => [ItemMaster::class, 'item_name'],
        'territories' => [Territory::class, 'name'],
        'schemes' => [PromotionalScheme::class, 'name'],
    ];

    public function visible(User $actor, ?Builder $query = null): Builder
    {
        return ($query ?? MarketingCampaign::query())->applyVisibility('MarketingCampaign', $actor);
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

        return $query
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match->whereLike('name', '%'.$search.'%')->orWhereLike('campaign_number', '%'.$search.'%')
            ))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->latest('id')->paginate(30)->through(fn (MarketingCampaign $record): array => $this->summary($actor, $record));
    }

    public function find(User $actor, int $id): MarketingCampaign
    {
        $this->authorizeRegister($actor);
        $campaign = $this->visible($actor)->withTrashed()->findOrFail($id);
        abort_unless($actor->can('view', $campaign), 403);

        return $campaign;
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

        return $type === 'items' ? $query->whereNotNull('item_type')->whereNull('parent_id') : $query;
    }

    public function options(User $actor, string $type, string $search): LengthAwarePaginator
    {
        [, $label] = self::CATALOGS[$type];
        $query = $this->catalog($actor, $type);
        $search = trim($search);

        return $query->when($search !== '', fn (Builder $query): Builder => $query->where(
            fn (Builder $match): Builder => $match->whereLike($label, '%'.$search.'%')
                ->when($type === 'items', fn (Builder $item): Builder => $item->orWhereLike('item_code', '%'.$search.'%'))
        ))->orderBy($label)->orderBy('id')->paginate(30, ['id', $label.' as label']);
    }

    public function detail(User $actor, MarketingCampaign $campaign): array
    {
        $this->loadConfiguration($campaign);
        $visible = $this->visibleConfiguration($actor, $campaign);

        return [
            ...$this->summary($actor, $campaign),
            'revision' => $this->revision($campaign),
            'description' => $campaign->description,
            'total_budget' => $campaign->total_budget,
            'scheme' => $visible['scheme'],
            'can_update' => ! $campaign->trashed() && $visible['complete'] && $actor->can('update', $campaign),
            'items' => $campaign->items->filter(fn (MarketingCampaignItem $item): bool => isset($visible['items'][$item->item_master_id]))
                ->map(fn (MarketingCampaignItem $item): array => [
                    ...$item->only(self::ITEM_FIELDS),
                    'item_name' => $visible['items'][$item->item_master_id],
                ])->values()->all(),
            'territory_quotas' => $campaign->territoryQuotas->filter(fn (MarketingCampaignTerritoryQuota $quota): bool => isset($visible['items'][$quota->item_master_id], $visible['territories'][$quota->territory_id]))
                ->map(fn (MarketingCampaignTerritoryQuota $quota): array => [
                    ...$quota->only([...self::QUOTA_FIELDS, 'used_quantity']),
                    'item_name' => $visible['items'][$quota->item_master_id],
                    'territory_name' => $visible['territories'][$quota->territory_id],
                    'remaining_quantity' => $quota->remainingQuota(),
                ])->values()->all(),
        ];
    }

    public function save(User $actor, ?int $id, array $input): MarketingCampaign
    {
        $this->authorizeRegister($actor);
        try {
            return (new MarketingCampaign)->getConnection()->transaction(function () use ($actor, $id, $input): MarketingCampaign {
                if ($id === null) {
                    abort_unless($actor->can('create', MarketingCampaign::class), 403);
                    $campaign = new MarketingCampaign;
                    NumberSeries::getNextNumber(MarketingCampaign::class);
                    NumberSeries::query()->where('model_type', MarketingCampaign::class)->whereNull('type_master_id')->lockForUpdate()->firstOrFail();
                } else {
                    $campaign = $this->visible($actor)->lockForUpdate()->findOrFail($id);
                    abort_unless($actor->can('view', $campaign) && $actor->can('update', $campaign), 403);
                }

                $this->loadConfiguration($campaign, lock: $campaign->exists);
                if ($campaign->exists) {
                    abort_unless($this->visibleConfiguration($actor, $campaign)['complete'], 403);
                    abort_unless(hash_equals($this->revision($campaign), $input['revision']), 409, 'The campaign changed. Reload it before saving.');
                }

                $this->validateSelections($actor, $campaign, $input);
                $campaign->fill(Arr::only($input, self::FIELDS) + ['promotional_scheme_id' => null, 'total_budget' => null, 'description' => null]);
                abort_unless($campaign->save(), 409, 'Could not save the campaign.');
                $this->saveItems($campaign, $input['items']);
                $this->saveQuotas($campaign, $input['territory_quotas']);

                return $campaign->unsetRelations();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'The campaign number is in use. Reload and try again.');
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

        return (new MarketingCampaign)->getConnection()->transaction(function () use ($actor, $ids, $ability): int {
            $records = $this->visible($actor)->withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $record) {
                abort_unless($actor->can($ability, $record), 403);
                abort_unless($record->trashed() === ($ability !== 'delete'), 409, 'A selected campaign changed. Reload your selection.');
                if ($ability === 'forceDelete') {
                    abort_if($record->sampleRequests()->withTrashed()->exists()
                        || $record->sgipDistributions()->withTrashed()->exists()
                        || $record->territoryQuotas()->where('used_quantity', '>', 0)->exists(),
                        409, 'Campaigns with sample requests, distributions or quota usage must be retained.');
                }
                abort_unless($record->{$ability}(), 409, 'Could not update the selected campaigns.');
            }

            return $records->count();
        }, attempts: 3);
    }

    private function authorizeRegister(User $actor): void
    {
        abort_unless($actor->can('viewAny', MarketingCampaign::class), 403);
    }

    private function summary(User $actor, MarketingCampaign $campaign): array
    {
        return [
            ...$campaign->only(['id', 'name', 'campaign_number']),
            'status' => $campaign->status->value,
            'start_date' => $campaign->start_date->toDateString(),
            'end_date' => $campaign->end_date->toDateString(),
            'trashed' => $campaign->trashed(),
            'can_view' => $actor->can('view', $campaign),
            'can_delete' => ! $campaign->trashed() && $actor->can('delete', $campaign),
            'can_restore' => $campaign->trashed() && $actor->can('restore', $campaign),
            'can_force_delete' => $campaign->trashed() && $actor->can('forceDelete', $campaign),
        ];
    }

    private function loadConfiguration(MarketingCampaign $campaign, bool $lock = false): void
    {
        $campaign->setRelation('items', $campaign->items()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get());
        $campaign->setRelation('territoryQuotas', $campaign->territoryQuotas()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get());
    }

    private function visibleConfiguration(User $actor, MarketingCampaign $campaign): array
    {
        $itemIds = $campaign->items->pluck('item_master_id')->merge($campaign->territoryQuotas->pluck('item_master_id'))->unique()->values()->all();
        $territoryIds = $campaign->territoryQuotas->pluck('territory_id')->unique()->values()->all();
        $items = $this->catalog($actor, 'items', true)->whereKey($itemIds)->pluck('item_name', 'id');
        $territories = $this->catalog($actor, 'territories', true)->whereKey($territoryIds)->pluck('name', 'id');
        $scheme = $campaign->promotional_scheme_id === null ? null : $this->catalog($actor, 'schemes', true)
            ->whereKey($campaign->promotional_scheme_id)->first(['id', 'name']);

        return [
            'items' => $items,
            'territories' => $territories,
            'scheme' => $scheme,
            'complete' => count($items) === count($itemIds) && count($territories) === count($territoryIds)
                && ($campaign->promotional_scheme_id === null || $scheme !== null),
        ];
    }

    private function revision(MarketingCampaign $campaign): string
    {
        return hash('sha256', json_encode([
            $campaign->only(self::FIELDS),
            $campaign->items->map->only(self::ITEM_FIELDS)->all(),
            $campaign->territoryQuotas->map->only(self::QUOTA_FIELDS)->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function validateSelections(User $actor, MarketingCampaign $campaign, array $input): void
    {
        $scheme = $input['promotional_scheme_id'] ?? null;
        if ($scheme !== null && (int) $scheme !== (int) $campaign->promotional_scheme_id
            && ! $this->catalog($actor, 'schemes')->whereKey((int) $scheme)->exists()) {
            throw ValidationException::withMessages(['promotional_scheme_id' => 'Choose an available promotional scheme.']);
        }

        $newItems = collect($input['items'])->pluck('item_master_id')->diff($campaign->items->pluck('item_master_id'))->unique();
        $existingQuotas = $campaign->territoryQuotas->mapWithKeys(fn (MarketingCampaignTerritoryQuota $quota): array => [$this->quotaKey($quota->only(self::QUOTA_FIELDS)) => true]);
        $quotaKeys = [];
        $territories = [];
        foreach ($input['territory_quotas'] as $index => $quota) {
            $key = $this->quotaKey($quota);
            if (isset($quotaKeys[$key])) {
                throw ValidationException::withMessages(["territory_quotas.{$index}.territory_id" => 'Use one quota per territory and item.']);
            }
            $quotaKeys[$key] = true;
            if (! $existingQuotas->has($key)) {
                $newItems->push($quota['item_master_id']);
                $territories[] = $quota['territory_id'];
            }
        }

        $this->requireAvailable($actor, 'items', $newItems->all(), 'items', 'Choose available campaign items.');
        $this->requireAvailable($actor, 'territories', $territories, 'territory_quotas', 'Choose available territories.');
    }

    private function requireAvailable(User $actor, string $catalog, array $ids, string $field, string $message): void
    {
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        if ($ids !== [] && $this->catalog($actor, $catalog)->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function saveItems(MarketingCampaign $campaign, array $items): void
    {
        $existing = $campaign->items->groupBy('item_master_id');
        $kept = [];
        foreach ($items as $input) {
            $item = $existing->get((int) $input['item_master_id'])?->first() ?? $campaign->items()->make();
            $item->fill($input + ['unit_value' => null])->save();
            $kept[] = $item->id;
        }
        $campaign->items()->whereKeyNot($kept)->delete();
    }

    private function saveQuotas(MarketingCampaign $campaign, array $quotas): void
    {
        $existing = $campaign->territoryQuotas->sortByDesc('used_quantity')
            ->groupBy(fn (MarketingCampaignTerritoryQuota $quota): string => $this->quotaKey($quota->only(self::QUOTA_FIELDS)));
        $kept = [];
        foreach ($quotas as $index => $input) {
            $quota = $existing->get($this->quotaKey($input))?->first() ?? $campaign->territoryQuotas()->make();
            $quota->fill($input);
            if ($quota->isDirty('quota_quantity') && (float) $quota->quota_quantity < (float) $quota->used_quantity) {
                throw ValidationException::withMessages(["territory_quotas.{$index}.quota_quantity" => 'Quota cannot be lower than the quantity already used.']);
            }
            $quota->save();
            $kept[] = $quota->id;
        }
        $removed = $campaign->territoryQuotas()->whereKeyNot($kept);
        if ((clone $removed)->where('used_quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['territory_quotas' => 'Quotas with recorded usage cannot be removed or reassigned.']);
        }
        $removed->delete();
    }

    private function quotaKey(array $quota): string
    {
        return (int) $quota['territory_id'].':'.(int) $quota['item_master_id'];
    }
}
