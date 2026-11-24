<?php

namespace App\Services\Inventory;

use App\Enums\InventoryDocumentStatus;
use App\Http\Requests\Api\V1\StockAdjustmentRequest;
use App\Models\InventoryAdjustment;
use App\Models\ItemMaster;
use App\Models\LocationMaster;
use App\Models\NumberSeries;
use App\Models\User;
use App\Services\OptionalModuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

class StockAdjustmentRegister
{
    public function canRead(User $actor): bool
    {
        return app(OptionalModuleService::class)->navigationEnabled('InventoryAdjustment') && $actor->can(['ViewAny:InventoryAdjustment', 'View:InventoryAdjustment']);
    }

    public function authorize(User $actor): void
    {
        abort_unless($this->canRead($actor), 403);
    }

    public function visible(User $actor): Builder
    {
        return InventoryAdjustment::query()->applyVisibility('InventoryAdjustment', $actor);
    }

    public function find(User $actor, int $id, bool $lock = false): InventoryAdjustment
    {
        $this->authorize($actor);

        return $this->visible($actor)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
    }

    public function catalog(User $actor, string $type): Builder
    {
        return $type === 'items' ? ItemMaster::query()->applyVisibility('ItemMaster', $actor)->whereNull('parent_id')
            : LocationMaster::query()->applyVisibility('LocationMaster', $actor)->where('is_active', true);
    }

    public function options(User $actor, string $type, string $search): array
    {
        $this->authorize($actor);
        $column = $type === 'items' ? 'item_name' : 'name';
        $code = $type === 'items' ? 'item_code' : 'location_code';
        $page = $this->catalog($actor, $type)->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $match->whereLike($column, '%'.$search.'%')->orWhereLike($code, '%'.$search.'%')))
            ->orderBy($column)->orderBy('id')->paginate(30);

        return ['data' => $page->getCollection()->map(fn ($record): array => ['id' => $record->id, 'label' => $record->{$column}, ...($type === 'items' ? ['unit_cost' => $record->purchase_price] : [])])->all(), 'meta' => ['last_page' => $page->lastPage()]];
    }

    public function revision(InventoryAdjustment $record): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive([$record->getAttributes(), $record->approval()->first()?->getAttributes()]), JSON_THROW_ON_ERROR));
    }

    public function summary(User $actor, InventoryAdjustment $record): array
    {
        $record->loadMissing(['item' => fn (BelongsTo $query) => $query->withTrashed(), 'location' => fn (BelongsTo $query) => $query->withTrashed(), 'approval', 'postedBy']);

        return [...$record->only(['id', 'adjustment_number', 'item_master_id', 'location_master_id', 'adjustment_type', 'reason', 'quantity', 'unit_cost', 'remarks', 'posted_by']),
            'item' => $record->item?->item_name, 'location' => $record->location?->name, 'adjustment_type' => $record->adjustment_type->value, 'type_label' => $record->adjustment_type->getLabel(),
            'status' => $record->status->value, 'status_label' => $record->status->getLabel(), 'approval_status' => $record->approval?->approval_status ?? 'draft',
            'posted_at' => $record->posted_at?->toIso8601String(), 'poster' => $record->postedBy?->name, 'created_at' => $record->created_at?->toIso8601String(),
            'can_edit' => $actor->can('update', $record) && $record->isEditable(),
            'actions' => collect(['submit', 'post', 'delete'])->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all()];
    }

    public function detail(User $actor, InventoryAdjustment $record): array
    {
        return [...$this->summary($actor, $record), 'revision' => $this->revision($record),
            'movements' => $record->movements()->orderBy('id')->get()->map(fn ($movement): array => [...$movement->only(['id', 'location_master_id', 'movement_type', 'quantity_in', 'quantity_out', 'balance_after', 'unit_cost', 'total_value']), 'movement_at' => $movement->movement_at?->toIso8601String()])->all()];
    }

    public function validateSelections(User $actor, array $input): void
    {
        if (trim((string) ($input['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'Enter the reason for this stock adjustment.']);
        }
        foreach (['item_master_id' => 'items', 'location_master_id' => 'locations'] as $field => $type) {
            if (! $this->catalog($actor, $type)->whereKey($input[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Choose an available item and active locations in your scope.']);
            }
        }
        if ((float) $input['quantity'] * (float) ($input['unit_cost'] ?? 0) > 99999999999.9999) {
            throw ValidationException::withMessages(['unit_cost' => 'The adjustment value exceeds the supported amount.']);
        }
    }

    public function save(User $actor, ?int $id, array $input): InventoryAdjustment
    {
        $this->authorize($actor);
        $input = Validator::make($input, StockAdjustmentRequest::saveRules($id !== null))->validate();

        return (new InventoryAdjustment)->getConnection()->transaction(function () use ($actor, $id, $input): InventoryAdjustment {
            $record = $id === null ? new InventoryAdjustment : $this->find($actor, $id, true);
            abort_unless($actor->can($id === null ? 'create' : 'update', $id === null ? InventoryAdjustment::class : $record), 403);
            if ($id === null) {
                NumberSeries::getNextNumber(InventoryAdjustment::class);
                NumberSeries::query()->where('model_type', InventoryAdjustment::class)->whereNull('type_master_id')->lockForUpdate()->firstOrFail();
            } else {
                $this->checkRevision($record, $input['revision']);
                abort_unless($record->isEditable(), 409, 'Pending, approved or posted adjustments cannot be edited.');
            }
            $this->validateSelections($actor, $input);
            $record->fill(Arr::only($input, ['item_master_id', 'location_master_id', 'adjustment_type', 'reason', 'quantity', 'unit_cost', 'remarks']) + ['remarks' => null, 'unit_cost' => null])->save();

            return $record->refresh();
        }, 3);
    }

    public function canAct(User $actor, InventoryAdjustment $record, string $action): bool
    {
        if (! $this->canRead($actor) || $record->trashed()) {
            return false;
        }

        return match ($action) {
            'delete' => $actor->can('delete', $record) && $record->isEditable(),
            'submit' => $actor->can('update', $record) && $record->isEditable() && $record->canStartApproval(),
            'post' => $actor->can('update', $record) && $record->status === InventoryDocumentStatus::Draft && ! $record->isPosted() && $record->canProceedAfterApproval(),
            default => false,
        };
    }

    public function act(User $actor, int $id, string $action, string $revision): void
    {
        $this->authorize($actor);
        (new InventoryAdjustment)->getConnection()->transaction(function () use ($actor, $id, $action, $revision): void {
            $record = $this->find($actor, $id, true);
            abort_unless($actor->can($action === 'delete' ? 'delete' : 'update', $record), 403);
            $this->checkRevision($record, $revision);
            abort_unless($this->canAct($actor, $record, $action), 409, 'This adjustment action is no longer available. Reload it.');
            if ($action !== 'delete') {
                $this->validateSelections($actor, $record->getAttributes());
            }
            try {
                match ($action) {
                    'delete' => $record->delete(), 'submit' => $record->startApprovalFromRules(), 'post' => app(InventoryService::class)->postAdjustment($record, $actor->id)
                };
            } catch (LogicException|RuntimeException $exception) {
                throw ValidationException::withMessages([$action => $exception->getMessage()]);
            }
        }, 3);
    }

    public function deleteMany(User $actor, array $ids): void
    {
        $this->authorize($actor);
        abort_unless($actor->can('deleteAny', InventoryAdjustment::class), 403);
        (new InventoryAdjustment)->getConnection()->transaction(function () use ($actor, $ids): void {
            sort($ids);
            $records = collect($ids)->map(fn (int $id): InventoryAdjustment => $this->find($actor, $id, true));
            foreach ($records as $record) {
                abort_unless($actor->can('delete', $record), 403);
                abort_unless($this->canAct($actor, $record, 'delete'), 409, 'Only editable drafts can be deleted.');
            }
            foreach ($records as $record) {
                $record->delete();
            }
        }, 3);
    }

    private function checkRevision(InventoryAdjustment $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The adjustment changed. Reload it before continuing.');
    }
}
