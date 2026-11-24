<?php

namespace App\Services\Inventory;

use App\Models\AccountMaster;
use App\Models\ApprovalOverride;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\ItemBrand;
use App\Models\ItemMaster;
use App\Models\Lead;
use App\Models\NumberSeries;
use App\Models\PackagingType;
use App\Models\SalesDocumentItem;
use App\Models\SampleIssueLine;
use App\Models\SampleRequestLine;
use App\Models\SgipDistributionItem;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Services\Field\FieldAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use LogicException;

class ItemRegister
{
    public const FIELDS = ['item_name', 'variant_name', 'sku', 'item_type', 'description', 'category_id', 'item_brand_id',
        'purchase_price', 'selling_price', 'hsn_code', 'discount', 'opening_stock', 'minimum_stock_level', 'reorder_quantity',
        'unit_of_measurement_id', 'lead_time', 'barcode', 'expiry_date', 'packaging_type_id', 'per_packing_qty'];

    public const RELATIONS = ['approval', 'category', 'brand', 'unitOfMeasurement', 'packagingType', 'taxes'];

    private const SELECTIONS = ['category_id' => 'categories', 'item_brand_id' => 'brands', 'unit_of_measurement_id' => 'units', 'packaging_type_id' => 'packaging'];

    public function authorizeRegister(User $actor): void
    {
        abort_unless($actor->can('viewAny', ItemMaster::class), 403);
    }

    public function visible(User $actor): Builder
    {
        return ItemMaster::query()->applyVisibility('ItemMaster', $actor);
    }

    public function find(User $actor, int $id, bool $lock = false): ItemMaster
    {
        $this->authorizeRegister($actor);
        $record = $this->visible($actor)->withTrashed()->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
        abort_unless($actor->can('view', $record), 403);

        return $record;
    }

    public function canChange(User $actor, ItemMaster $record, string $ability = 'update'): bool
    {
        return ! $record->trashed() && $actor->can($ability, $record)
            && $record->getApprovalStatus() !== 'pending'
            && ($record->getApprovalStatus() !== 'approved' || $actor->can('overrideApproval', $record));
    }

    public function catalog(User $actor, string $type): Builder
    {
        [$model] = match ($type) {
            'categories' => [Category::class], 'brands' => [ItemBrand::class], 'units' => [UnitOfMeasurement::class],
            'packaging' => [PackagingType::class], 'taxes' => [Tax::class], 'variants' => [ItemMaster::class],
            'suppliers' => [AccountMaster::class], 'leads' => [Lead::class],
        };
        $query = $model::query()->applyVisibility(class_basename($model), $actor);
        if (in_array($type, ['suppliers', 'leads'], true) && ! $actor->can(['ViewAny:'.class_basename($model), 'View:'.class_basename($model)])) {
            $query->whereKey([]);
        }

        return match ($type) {
            'variants' => $query->whereNull('parent_id')->whereDoesntHave('variants', fn (Builder $children) => $children->withTrashed()),
            'taxes' => $query->where('is_active', true),
            default => $query,
        };
    }

    public function options(User $actor, string $type, string $search): array
    {
        $this->authorizeRegister($actor);
        abort_unless($actor->can('create', ItemMaster::class) || $actor->can('Update:ItemMaster'), 403);
        $column = $type === 'variants' ? 'item_name' : ($type === 'leads' ? 'reference_code' : 'name');
        $page = $this->catalog($actor, $type)->when($search !== '', fn (Builder $query) => $query->whereLike($column, '%'.$search.'%'))
            ->orderBy($column)->orderBy('id')->paginate(30);

        return ['data' => $page->getCollection()->map(fn ($record): array => ['id' => $record->id, 'label' => $record->{$column}])->all(),
            'meta' => ['last_page' => $page->lastPage()]];
    }

    public function summary(User $actor, ItemMaster $record): array
    {
        return [
            ...$record->only(['id', 'item_code', 'item_name', 'variant_name', 'parent_id', 'sku', 'selling_price', 'expiry_date']),
            'item_type' => $record->item_type?->value,
            'approval_status' => $record->getApprovalStatus(),
            'trashed' => $record->trashed(), 'can_view' => $actor->can('view', $record),
            'actions' => collect(['submit', 'delete', 'restore', 'force-delete', 'dissociate'])
                ->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all(),
        ];
    }

    public function detail(User $actor, ItemMaster $record): array
    {
        $record->loadMissing(self::RELATIONS);

        return [
            ...$this->summary($actor, $record), ...$record->only(self::FIELDS),
            'item_type' => $record->item_type?->value,
            'revision' => $this->revision($record), 'can_edit' => $this->canChange($actor, $record),
            'category' => $record->category?->name, 'brand' => $record->brand?->name, 'unit' => $record->unitOfMeasurement?->name,
            'packaging' => $record->packagingType?->name,
            'tax_ids' => $record->taxes->modelKeys(),
            'taxes' => $record->taxes->map->only(['id', 'name'])->values()->all(),
            'relations' => [
                'variants' => $record->parent_id === null,
                'suppliers' => $actor->can(['ViewAny:AccountMaster', 'View:AccountMaster']),
                'leads' => $actor->can(['ViewAny:Lead', 'View:Lead']),
                'stocks' => $this->canReadStock($actor),
            ],
            'can_create_supplier' => $this->canChange($actor, $record) && $actor->can(['ViewAny:AccountMaster', 'View:AccountMaster', 'Create:AccountMaster']),
        ];
    }

    public function revision(ItemMaster $record): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive([
            $record->getAttributes(), $record->approval()->first()?->getAttributes(),
            $record->taxes()->orderBy('taxes.id')->pluck('taxes.id')->all(),
            $record->suppliers()->orderBy('account_masters.id')->pluck('account_masters.id')->all(),
            $record->leads()->orderBy('leads.id')->get()->map(fn (Lead $lead): array => [$lead->id, $lead->pivot->quantity, $lead->pivot->price])->all(),
            $record->variants()->withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
        ]), JSON_THROW_ON_ERROR));
    }

    public function save(User $actor, ?int $id, array $input, ?int $parentId = null): ItemMaster
    {
        $this->authorizeRegister($actor);

        try {
            return (new ItemMaster)->getConnection()->transaction(function () use ($actor, $id, $input, $parentId): ItemMaster {
                $parent = $parentId === null ? null : $this->find($actor, $parentId, lock: true);
                if ($parent !== null) {
                    abort_unless($this->canChange($actor, $parent) && $parent->parent_id === null, 403);
                }
                $record = $id === null ? new ItemMaster : $this->find($actor, $id, lock: true);
                if ($id === null) {
                    abort_unless($actor->can('create', ItemMaster::class), 403);
                    NumberSeries::getNextNumber(ItemMaster::class);
                    NumberSeries::query()->where('model_type', ItemMaster::class)->whereNull('type_master_id')->lockForUpdate()->firstOrFail();
                    $record->parent_id = $parent?->id;
                } else {
                    abort_unless($this->canChange($actor, $record), 403);
                    if ($parent !== null) {
                        abort_unless($record->parent_id === $parent->id, 404);
                    }
                    $this->checkRevision($record, $input['revision']);
                    if ($record->parent_id !== null && $parent === null) {
                        $parent = $this->find($actor, $record->parent_id, lock: true);
                        abort_unless($this->canChange($actor, $parent), 403);
                    }
                }
                foreach (self::SELECTIONS as $field => $type) {
                    $value = $input[$field] ?? null;
                    if ($value !== null && (int) $value !== (int) $record->{$field} && ! $this->catalog($actor, $type)->whereKey($value)->exists()) {
                        throw ValidationException::withMessages([$field => 'Select an available option.']);
                    }
                }
                $taxIds = array_map(intval(...), $input['tax_ids'] ?? []);
                $existingTaxes = $record->exists ? $record->taxes()->pluck('taxes.id')->all() : [];
                $newTaxIds = array_diff($taxIds, $existingTaxes);
                if ($this->catalog($actor, 'taxes')->whereKey($newTaxIds)->count() !== count($newTaxIds)) {
                    throw ValidationException::withMessages(['tax_ids' => 'Select available taxes.']);
                }
                $sku = filled($input['sku'] ?? null) ? trim($input['sku']) : null;
                $input['sku'] = $sku;
                if ($sku !== null && ItemMaster::query()->withTrashed()->where('sku', $sku)->when($id, fn (Builder $query) => $query->whereKeyNot($id))->exists()) {
                    throw ValidationException::withMessages(['sku' => 'This SKU is already in use, including deleted items.']);
                }
                $record->fill(Arr::only($input, self::FIELDS));
                if (array_key_exists('category_id', $input)) {
                    $record->category_type = $input['category_id'] === null ? null : Category::class;
                }
                if ($parent !== null) {
                    $record->item_name = $parent->item_name;
                    if (blank($record->variant_name)) {
                        throw ValidationException::withMessages(['variant_name' => 'Enter a variant name.']);
                    }
                }
                $this->logOverride($actor, $record);
                abort_unless($record->save(), 409, 'Could not save the item.');
                $record->taxes()->sync($taxIds);

                return $record->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['sku' => 'An item with this SKU or code already exists. Reload and try again.']);
        }
    }

    public function canAct(User $actor, ItemMaster $record, string $action): bool
    {
        if (! $actor->can('view', $record)) {
            return false;
        }
        if ($record->trashed()) {
            return $record->parent_id !== null && in_array($action, ['restore', 'force-delete'], true)
                && $actor->can($action === 'restore' ? 'restore' : 'forceDelete', $record)
                && $record->getApprovalStatus() !== 'pending'
                && ($record->getApprovalStatus() !== 'approved' || $actor->can('overrideApproval', $record));
        }

        return match ($action) {
            'submit' => $this->canChange($actor, $record) && $record->canSendForApproval(),
            'delete' => $this->canChange($actor, $record, 'delete'),
            'dissociate' => $record->parent_id !== null && $this->canChange($actor, $record),
            default => false,
        };
    }

    public function act(User $actor, array $ids, string $action, ?string $revision = null, bool $bulk = false, ?int $parentId = null, ?string $parentRevision = null): void
    {
        $this->authorizeRegister($actor);
        if ($bulk) {
            $ability = match ($action) {
                'delete' => 'DeleteAny', 'restore' => 'RestoreAny', 'force-delete' => 'ForceDeleteAny', default => 'Update'
            };
            abort_unless($actor->can($ability.':ItemMaster'), 403);
        }
        (new ItemMaster)->getConnection()->transaction(function () use ($actor, $ids, $action, $revision, $parentId, $parentRevision): void {
            $parent = $parentId === null ? null : $this->find($actor, $parentId, lock: true);
            if ($parent !== null) {
                abort_unless($this->canChange($actor, $parent), 403);
            }
            if ($parent !== null && $parentRevision !== null) {
                $this->checkRevision($parent, $parentRevision);
            }
            $records = $this->visible($actor)->withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->with('approval')->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $record) {
                abort_unless($actor->can('view', $record), 403);
                if ($parent !== null) {
                    abort_unless($record->parent_id === $parent->id, 404);
                }
                if ($record->parent_id !== null && $parent === null) {
                    abort_unless($this->canChange($actor, $this->find($actor, $record->parent_id, lock: true)), 403);
                }
                abort_unless($this->canAct($actor, $record, $action), 403);
                if ($revision !== null) {
                    $this->checkRevision($record, $revision);
                }
                $this->logOverride($actor, $record);
                if ($action === 'submit') {
                    try {
                        $record->startApprovalFromRules();
                    } catch (LogicException $exception) {
                        throw ValidationException::withMessages(['approval' => $exception->getMessage()]);
                    }
                } elseif ($action === 'delete') {
                    abort_if($record->variants()->withTrashed()->exists(), 409, 'Dissociate or permanently remove variants before deleting their parent.');
                    abort_unless($record->delete(), 409);
                } elseif ($action === 'dissociate') {
                    abort_unless($record->update(['parent_id' => null]), 409);
                } elseif ($action === 'restore') {
                    abort_unless($record->restore(), 409);
                } else {
                    abort_if($record->variants()->withTrashed()->exists() || $record->inventoryMovements()->exists()
                        || $record->inventoryStocks()->exists() || $record->purchaseOrderLines()->exists()
                        || $record->customerPrices()->withTrashed()->exists() || $record->leads()->exists()
                        || SgipDistributionItem::withTrashed()->where('item_master_id', $record->id)->exists()
                        || SampleIssueLine::withTrashed()->where('item_master_id', $record->id)->exists()
                        || SampleRequestLine::withTrashed()->where('item_master_id', $record->id)->exists()
                        || SalesDocumentItem::withTrashed()->where('item_master_id', $record->id)->exists(), 409, 'Items with stock or commercial history must be retained.');
                    abort_unless($record->forceDelete(), 409);
                }
            }
        }, 3);
    }

    public function related(User $actor, int $id, string $type, string $search = '', string $trash = 'active'): array
    {
        $record = $this->find($actor, $id);
        if ($type === 'stocks') {
            abort_unless($this->canReadStock($actor), 403);
            $page = app(StockRegister::class)->visible($actor)->where('item_master_id', $record->id)
                ->with(['location' => fn (BelongsTo $location) => $location->withTrashed()])
                ->orderBy('id')->paginate(30)->through(fn (InventoryStock $stock): array => [
                    ...$stock->only(['id', 'quantity_on_hand', 'quantity_reserved', 'quantity_available', 'average_cost', 'last_movement_at']), 'label' => $stock->location?->name,
                ]);
        } elseif ($type === 'variants') {
            $query = $record->variants()->applyVisibility('ItemMaster', $actor)->with('approval');
            if ($trash === 'all') {
                $query->withTrashed();
            } elseif ($trash === 'trashed') {
                $query->onlyTrashed();
            }
            $page = $query->when($search !== '', fn (Builder $query) => $query->whereLike('variant_name', '%'.$search.'%'))
                ->orderBy('id')->paginate(30)->through(fn (ItemMaster $item): array => $this->summary($actor, $item));
        } else {
            $model = $type === 'suppliers' ? AccountMaster::class : Lead::class;
            abort_unless($actor->can(['ViewAny:'.class_basename($model), 'View:'.class_basename($model)]), 403);
            $column = $type === 'suppliers' ? 'name' : 'reference_code';
            $page = $record->{$type}()->applyVisibility(class_basename($model), $actor)
                ->when($search !== '', fn (Builder $query) => $query->whereLike($column, '%'.$search.'%'))
                ->orderBy($column)->paginate(30)->through(fn ($row): array => [
                    'id' => $row->id, 'label' => $row->{$column},
                    'can_edit' => $actor->can('update', $row), 'can_delete' => $type === 'suppliers' && $actor->can('delete', $row),
                    ...($type === 'leads' ? ['quantity' => $row->pivot->quantity, 'price' => $row->pivot->price] : []),
                ]);
        }

        return $page->toArray() + ['can_manage' => $this->canChange($actor, $record), 'revision' => $this->revision($record)];
    }

    public function relate(User $actor, int $id, string $type, string $action, array $ids, string $revision, array $values = []): void
    {

        if ($type === 'suppliers' && $action === 'delete' && count($ids) > 1) {
            abort_unless($actor->can('DeleteAny:AccountMaster'), 403);
        }
        abort_unless(in_array($action, match ($type) {
            'variants' => ['attach'], 'suppliers' => ['attach', 'delete'], 'leads' => ['attach', 'update', 'detach']
        }, true), 422);
        (new ItemMaster)->getConnection()->transaction(function () use ($actor, $id, $type, $action, $ids, $revision, $values): void {
            $record = $this->find($actor, $id, lock: true);
            abort_unless($this->canChange($actor, $record), 403);
            $this->checkRevision($record, $revision);
            if ($type === 'variants') {
                abort_unless($record->parent_id === null && $action === 'attach', 422);
                $rows = $this->catalog($actor, 'variants')->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            } else {
                $rows = $this->catalog($actor, $type)->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            }
            abort_unless($rows->count() === count($ids), 404);
            $this->logOverride($actor, $record);
            foreach ($rows as $row) {
                if ($type === 'variants') {
                    abort_unless($row->id !== $record->id && $this->canChange($actor, $row), 403);
                    abort_unless($row->update(['parent_id' => $record->id]), 409);
                } else {
                    if ($type === 'leads') {
                        abort_unless($actor->can('update', $row) && ! $row->isApprovalPending() && ($row->getApprovalStatus() !== 'approved' || $actor->can('overrideApproval', $row)), 403);
                    }
                    $relation = $record->{$type}();
                    if ($action === 'detach' || $action === 'delete' || $action === 'update') {
                        abort_unless($relation->whereKey($row->id)->exists(), 404);
                    }
                    if ($action === 'delete') {
                        abort_unless($type === 'suppliers', 422);
                        app(FieldAccountService::class)->applyLifecycle($actor, 'delete', [$row->id]);
                    } elseif ($action === 'detach') {
                        $relation->detach($row->id);
                    } else {
                        $pivot = $type === 'leads' ? Arr::only($values, ['quantity', 'price']) : [];
                        $relation->syncWithoutDetaching([$row->id => $pivot]);
                    }
                }
            }
        }, 3);
    }

    public function canReadStock(User $actor): bool
    {
        return app(StockRegister::class)->canRead($actor);
    }

    private function checkRevision(ItemMaster $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The item changed. Reload it before continuing.');
    }

    private function logOverride(User $actor, ItemMaster $record): void
    {
        if ($record->exists && $record->getApprovalStatus() === 'approved') {
            ApprovalOverride::query()->create(['model_type' => ItemMaster::class, 'model_id' => $record->id, 'user_id' => $actor->id, 'reason' => 'Authorized item maintenance']);
        }
    }
}
