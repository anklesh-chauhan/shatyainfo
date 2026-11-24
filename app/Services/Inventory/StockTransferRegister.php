<?php

namespace App\Services\Inventory;

use App\Enums\InventoryDocumentStatus;
use App\Http\Requests\Api\V1\StockTransferRequest;
use App\Models\InventoryTransfer;
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

class StockTransferRegister
{
    public function canRead(User $actor): bool
    {
        return app(OptionalModuleService::class)->navigationEnabled('InventoryTransfer') && $actor->can(['ViewAny:InventoryTransfer', 'View:InventoryTransfer']);
    }

    public function authorize(User $actor): void
    {
        abort_unless($this->canRead($actor), 403);
    }

    public function visible(User $actor): Builder
    {
        return InventoryTransfer::query()->applyVisibility('InventoryTransfer', $actor);
    }

    public function find(User $actor, int $id, bool $lock = false): InventoryTransfer
    {
        $this->authorize($actor);

        return $this->visible($actor)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
    }

    public function catalog(User $actor, string $type): Builder
    {
        return $type === 'items' ? ItemMaster::query()->applyVisibility('ItemMaster', $actor)
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

    public function revision(InventoryTransfer $record): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive([$record->getAttributes(), $record->approval()->first()?->getAttributes()]), JSON_THROW_ON_ERROR));
    }

    public function summary(User $actor, InventoryTransfer $record): array
    {
        $record->loadMissing(['item' => fn (BelongsTo $query) => $query->withTrashed(), 'fromLocation' => fn (BelongsTo $query) => $query->withTrashed(),
            'toLocation' => fn (BelongsTo $query) => $query->withTrashed(), 'approval', 'postedBy']);

        return [...$record->only(['id', 'transfer_number', 'item_master_id', 'from_location_master_id', 'to_location_master_id', 'quantity', 'unit_cost', 'remarks', 'posted_by']),
            'item' => $record->item?->item_name, 'source' => $record->fromLocation?->name, 'destination' => $record->toLocation?->name,
            'status' => $record->status->value, 'status_label' => $record->status->getLabel(), 'approval_status' => $record->approval?->approval_status ?? 'draft',
            'posted_at' => $record->posted_at?->toIso8601String(), 'poster' => $record->postedBy?->name, 'created_at' => $record->created_at?->toIso8601String(),
            'can_edit' => $actor->can('update', $record) && $record->isEditable(),
            'actions' => collect(['submit', 'post', 'delete'])->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all()];
    }

    public function detail(User $actor, InventoryTransfer $record): array
    {
        return [...$this->summary($actor, $record), 'revision' => $this->revision($record),
            'movements' => $record->movements()->orderBy('id')->get()->map(fn ($movement): array => [...$movement->only(['id', 'location_master_id', 'movement_type', 'quantity_in', 'quantity_out', 'balance_after', 'unit_cost', 'total_value']), 'movement_at' => $movement->movement_at?->toIso8601String()])->all()];
    }

    public function validateSelections(User $actor, array $input): void
    {
        foreach (['item_master_id' => 'items', 'from_location_master_id' => 'locations', 'to_location_master_id' => 'locations'] as $field => $type) {
            if (! $this->catalog($actor, $type)->whereKey($input[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Choose an available item and active locations in your scope.']);
            }
        }
        if ((int) $input['from_location_master_id'] === (int) $input['to_location_master_id']) {
            throw ValidationException::withMessages(['to_location_master_id' => 'Choose different source and destination locations.']);
        }
        if ((float) $input['quantity'] * (float) $input['unit_cost'] > 99999999999.9999) {
            throw ValidationException::withMessages(['unit_cost' => 'The transfer value exceeds the supported amount.']);
        }
    }

    public function save(User $actor, ?int $id, array $input): InventoryTransfer
    {
        $this->authorize($actor);
        $input = Validator::make($input, StockTransferRequest::saveRules($id !== null))->validate();

        return (new InventoryTransfer)->getConnection()->transaction(function () use ($actor, $id, $input): InventoryTransfer {
            $record = $id === null ? new InventoryTransfer : $this->find($actor, $id, true);
            abort_unless($actor->can($id === null ? 'create' : 'update', $id === null ? InventoryTransfer::class : $record), 403);
            if ($id === null) {
                NumberSeries::getNextNumber(InventoryTransfer::class);
                NumberSeries::query()->where('model_type', InventoryTransfer::class)->whereNull('type_master_id')->lockForUpdate()->firstOrFail();
            } else {
                $this->checkRevision($record, $input['revision']);
                abort_unless($record->isEditable(), 409, 'Pending, approved or posted transfers cannot be edited.');
            }
            $this->validateSelections($actor, $input);
            $record->fill(Arr::only($input, ['item_master_id', 'from_location_master_id', 'to_location_master_id', 'quantity', 'unit_cost', 'remarks']) + ['remarks' => null])->save();

            return $record->refresh();
        }, 3);
    }

    public function canAct(User $actor, InventoryTransfer $record, string $action): bool
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
        (new InventoryTransfer)->getConnection()->transaction(function () use ($actor, $id, $action, $revision): void {
            $record = $this->find($actor, $id, true);
            abort_unless($actor->can($action === 'delete' ? 'delete' : 'update', $record), 403);
            $this->checkRevision($record, $revision);
            abort_unless($this->canAct($actor, $record, $action), 409, 'This transfer action is no longer available. Reload it.');
            if ($action !== 'delete') {
                $this->validateSelections($actor, $record->getAttributes());
            }
            try {
                match ($action) {
                    'delete' => $record->delete(), 'submit' => $record->startApprovalFromRules(), 'post' => app(InventoryService::class)->postTransfer($record, $actor->id)
                };
            } catch (LogicException|RuntimeException $exception) {
                throw ValidationException::withMessages([$action => $exception->getMessage()]);
            }
        }, 3);
    }

    private function checkRevision(InventoryTransfer $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The transfer changed. Reload it before continuing.');
    }
}
