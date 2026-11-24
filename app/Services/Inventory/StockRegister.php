<?php

namespace App\Services\Inventory;

use App\Models\InventoryStock;
use App\Models\ItemMaster;
use App\Models\LocationMaster;
use App\Models\User;
use App\Services\OptionalModuleService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRegister
{
    public function canRead(User $actor): bool
    {
        return app(OptionalModuleService::class)->navigationEnabled('InventoryStock')
            && $actor->can(['ViewAny:InventoryStock', 'View:InventoryStock']);
    }

    public function authorize(User $actor): void
    {
        abort_unless($this->canRead($actor), 403);
    }

    public function visible(User $actor): Builder
    {
        return InventoryStock::query()->applyVisibility('InventoryStock', $actor)
            ->when(! $this->canRead($actor), fn (Builder $query) => $query->whereKey([]))
            ->whereHas('location', fn (Builder $query) => $query->withTrashed()->applyVisibility('LocationMaster', $actor));
    }

    public function catalog(User $actor, string $type): Builder
    {
        $column = $type === 'items' ? 'item_master_id' : 'location_master_id';
        $model = $type === 'items' ? ItemMaster::class : LocationMaster::class;

        return $model::query()->withTrashed()->whereIn('id', $this->visible($actor)->select($column));
    }

    public function options(User $actor, string $type, string $search): array
    {
        $this->authorize($actor);
        $column = $type === 'items' ? 'item_name' : 'name';
        $code = $type === 'items' ? 'item_code' : 'location_code';
        $page = $this->catalog($actor, $type)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match->whereLike($column, '%'.$search.'%')->orWhereLike($code, '%'.$search.'%')))
            ->orderBy($column)->orderBy('id')->paginate(30);

        return ['data' => $page->getCollection()->map(fn ($record): array => [
            'id' => $record->id, 'label' => $record->{$column}.($record->trashed() ? ' (Archived)' : ''),
        ])->all(), 'meta' => ['last_page' => $page->lastPage()]];
    }

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorize($actor);
        $search = trim((string) ($filters['search'] ?? ''));
        $query = $this->visible($actor)->with([
            'item' => fn (BelongsTo $query) => $query->withTrashed(),
            'location' => fn (BelongsTo $query) => $query->withTrashed(),
        ])->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
            ->whereHas('item', fn (Builder $item) => $item->withTrashed()->where(fn (Builder $item) => $item->whereLike('item_name', '%'.$search.'%')
                ->orWhereLike('item_code', '%'.$search.'%')->orWhereLike('variant_name', '%'.$search.'%')->orWhereLike('sku', '%'.$search.'%')))
            ->orWhereHas('location', fn (Builder $location) => $location->withTrashed()->where(fn (Builder $location) => $location->whereLike('name', '%'.$search.'%')->orWhereLike('location_code', '%'.$search.'%')))))
            ->when($filters['item_master_id'] ?? null, fn (Builder $query, int $id) => $query->where('item_master_id', $id))
            ->when($filters['location_master_id'] ?? null, fn (Builder $query, int $id) => $query->where('location_master_id', $id));
        $sort = $filters['sort'] ?? 'last_movement_at';
        $direction = $filters['direction'] ?? 'desc';
        if (in_array($sort, ['item_name', 'item_code'], true)) {
            $query->orderBy(ItemMaster::query()->withTrashed()->select($sort)->whereColumn('item_masters.id', 'inventory_stocks.item_master_id'), $direction);
        } elseif ($sort === 'location') {
            $query->orderBy(LocationMaster::query()->withTrashed()->select('name')->whereColumn('location_masters.id', 'inventory_stocks.location_master_id'), $direction);
        } else {
            if ($sort === 'last_movement_at') {
                $query->orderByRaw('last_movement_at IS NULL');
            }
            $query->orderBy($sort, $direction);
        }

        return $query->orderBy('inventory_stocks.id', $direction)->paginate(30)->through(fn (InventoryStock $stock): array => [
            ...$stock->only(['id', 'item_master_id', 'location_master_id', 'quantity_on_hand', 'quantity_reserved', 'quantity_available', 'average_cost']),
            'item_code' => $stock->item?->item_code, 'item_name' => $stock->item?->item_name,
            'variant_name' => $stock->item?->variant_name, 'sku' => $stock->item?->sku,
            'location' => $stock->location?->name, 'location_code' => $stock->location?->location_code,
            'item_archived' => $stock->item?->trashed() ?? false, 'location_archived' => $stock->location?->trashed() ?? false,
            'last_movement_at' => $stock->last_movement_at?->toIso8601String(),
        ]);
    }
}
