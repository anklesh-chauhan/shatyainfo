<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\ItemMaster;
use App\Models\LocationMaster;
use App\Models\User;
use App\Services\OptionalModuleService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLedger
{
    public const TYPES = [
        'grn_receipt' => 'GRN receipt',
        'adjustment_receipt' => 'Adjustment receipt',
        'adjustment_issue' => 'Adjustment issue',
        'adjustment_increase' => 'Adjustment increase',
        'adjustment_decrease' => 'Adjustment decrease',
        'transfer_in' => 'Transfer in',
        'transfer_out' => 'Transfer out',
        'audit_variance' => 'Audit variance',
        'delivery_challan' => 'Delivery challan',
        'sample_issue_out' => 'Sample issue out',
        'sample_issue_in' => 'Sample issue in',
        'sgip_distribution' => 'SGIP distribution',
    ];

    public function canRead(User $actor): bool
    {
        return app(OptionalModuleService::class)->navigationEnabled('InventoryMovement')
            && $actor->can(['ViewAny:InventoryMovement', 'View:InventoryMovement']);
    }

    public function authorize(User $actor): void
    {
        abort_unless($this->canRead($actor), 403);
    }

    public function visible(User $actor): Builder
    {
        return InventoryMovement::query()->applyVisibility('InventoryMovement', $actor)
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
            ->whereLike('movement_type', '%'.$search.'%')->orWhereLike('remarks', '%'.$search.'%')
            ->orWhereHas('item', fn (Builder $item) => $item->withTrashed()->where(fn (Builder $item) => $item->whereLike('item_name', '%'.$search.'%')->orWhereLike('item_code', '%'.$search.'%')))
            ->orWhereHas('location', fn (Builder $location) => $location->withTrashed()->where(fn (Builder $location) => $location->whereLike('name', '%'.$search.'%')->orWhereLike('location_code', '%'.$search.'%')))))
            ->when($filters['item_master_id'] ?? null, fn (Builder $query, int $id) => $query->where('item_master_id', $id))
            ->when($filters['location_master_id'] ?? null, fn (Builder $query, int $id) => $query->where('location_master_id', $id))
            ->when($filters['movement_type'] ?? null, fn (Builder $query, string $type) => $query->where('movement_type', $type))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('movement_at', '>=', $date))
            ->when($filters['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('movement_at', '<=', $date));
        $sort = $filters['sort'] ?? 'movement_at';
        $direction = $filters['direction'] ?? 'desc';
        if (in_array($sort, ['item_name', 'item_code'], true)) {
            $query->orderBy(ItemMaster::query()->withTrashed()->select($sort)->whereColumn('item_masters.id', 'inventory_movements.item_master_id'), $direction);
        } elseif ($sort === 'location') {
            $query->orderBy(LocationMaster::query()->withTrashed()->select('name')->whereColumn('location_masters.id', 'inventory_movements.location_master_id'), $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        return $query->orderBy('inventory_movements.id', $direction)->paginate(30)->through(fn (InventoryMovement $movement): array => [
            ...$movement->only(['id', 'item_master_id', 'location_master_id', 'movement_type', 'quantity_in', 'quantity_out', 'balance_after', 'unit_cost', 'total_value', 'reference_id', 'remarks']),
            'movement_label' => self::TYPES[$movement->movement_type] ?? $movement->movement_type,
            'reference_type' => class_basename((string) $movement->reference_type),
            'item_code' => $movement->item?->item_code, 'item_name' => $movement->item?->item_name,
            'location' => $movement->location?->name, 'location_code' => $movement->location?->location_code,
            'item_archived' => $movement->item?->trashed() ?? false, 'location_archived' => $movement->location?->trashed() ?? false,
            'movement_at' => $movement->movement_at?->toIso8601String(),
        ]);
    }
}
