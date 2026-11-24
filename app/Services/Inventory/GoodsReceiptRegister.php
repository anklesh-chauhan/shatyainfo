<?php

namespace App\Services\Inventory;

use App\Enums\GoodsReceiptNoteStatus;
use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\Api\V1\GoodsReceiptRegisterRequest;
use App\Models\ApprovalSetting;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteLine;
use App\Models\InventoryMovement;
use App\Models\NumberSeries;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

class GoodsReceiptRegister
{
    public function authorize(User $actor): void
    {
        abort_unless($actor->can(['ViewAny:GoodsReceiptNote', 'View:GoodsReceiptNote']), 403);
    }

    public function visible(User $actor): Builder
    {
        return GoodsReceiptNote::query()->applyVisibility('GoodsReceiptNote', $actor);
    }

    public function find(User $actor, int $id, bool $lock = false): GoodsReceiptNote
    {
        $this->authorize($actor);

        return $this->visible($actor)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
    }

    public function catalog(User $actor, string $type): Builder
    {
        if ($type !== 'purchase-orders') {
            return app(PurchaseOrderRegister::class)->catalog($actor, $type);
        }
        $query = PurchaseOrder::query()->applyVisibility('PurchaseOrder', $actor)
            ->when(! $actor->can(['ViewAny:PurchaseOrder', 'View:PurchaseOrder']), fn (Builder $query): Builder => $query->whereKey([]))
            ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived])
            ->whereHas('lines', fn (Builder $lines): Builder => $lines->whereColumn('quantity_ordered', '>', 'quantity_received'));
        if (ApprovalSetting::moduleRequiresApproval(PurchaseOrder::class)) {
            $query->whereHas('approval', fn (Builder $approval): Builder => $approval->where('approval_status', 'approved'));
        }

        return $query;
    }

    public function purchaseOrder(User $actor, int $id, bool $lock = false): PurchaseOrder
    {
        $this->authorize($actor);
        $record = $this->catalog($actor, 'purchase-orders')->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
        $record->assertApprovalComplete();

        return $record;
    }

    public function orderData(User $actor, int $id): array
    {
        $record = $this->purchaseOrder($actor, $id);
        $record->load(['supplier', 'location', 'lines.item']);

        return [...$record->only(['id', 'document_number', 'supplier_id', 'location_master_id']),
            'supplier' => $record->supplier?->name, 'location' => $record->location?->name,
            'lines' => $record->lines->filter(fn (PurchaseOrderLine $line): bool => $line->remainingQuantity() > 0)
                ->map(fn (PurchaseOrderLine $line): array => [
                    'purchase_order_line_id' => $line->id, 'item_master_id' => $line->item_master_id, 'item' => $line->item?->item_name,
                    'quantity_received' => $line->remainingQuantity(), 'unit_cost' => $line->unit_price,
                ])->values()->all(),
        ];
    }

    public function orderLineOptions(User $actor, int $id): array
    {
        if (! $actor->can(['ViewAny:PurchaseOrder', 'View:PurchaseOrder'])) {
            return [];
        }
        $order = PurchaseOrder::query()->applyVisibility('PurchaseOrder', $actor)->find($id);

        return $order?->lines()->with(['item' => fn (BelongsTo $query) => $query->withTrashed()])->get()
            ->mapWithKeys(fn (PurchaseOrderLine $line): array => [$line->id => ($line->item?->item_name ?? 'Item').' (Remaining: '.$line->remainingQuantity().')'])->all() ?? [];
    }

    public function options(User $actor, string $type, string $search): array
    {
        $this->authorize($actor);
        $column = match ($type) {
            'items' => 'item_name', 'purchase-orders' => 'document_number', default => 'name'
        };
        $page = $this->catalog($actor, $type)->when($search !== '', fn (Builder $query): Builder => $query->whereLike($column, '%'.$search.'%'))
            ->orderBy($column)->orderBy('id')->paginate(30);

        return ['data' => $page->getCollection()->map(fn ($record): array => [
            'id' => $record->id, 'label' => $record->{$column}, ...($type === 'items' ? ['unit_cost' => $record->purchase_price] : []),
        ])->all(), 'meta' => ['last_page' => $page->lastPage()]];
    }

    public function revision(GoodsReceiptNote $record): string
    {
        $po = $record->purchaseOrder()->first();

        return hash('sha256', json_encode(Arr::sortRecursive([
            $record->getAttributes(), $record->lines()->orderBy('id')->get()->map->getAttributes()->all(),
            $record->approval()->first()?->getAttributes(), $po?->getAttributes(),
            $po?->lines()->orderBy('id')->get()->map->getAttributes()->all(),
        ]), JSON_THROW_ON_ERROR));
    }

    public function summary(User $actor, GoodsReceiptNote $record): array
    {
        $record->loadMissing(['purchaseOrder' => fn (BelongsTo $query) => $query->withTrashed(),
            'supplier' => fn (BelongsTo $query) => $query->withTrashed(), 'location' => fn (BelongsTo $query) => $query->withTrashed(), 'approval', 'postedBy']);

        return [...$record->only(['id', 'document_number', 'purchase_order_id', 'supplier_id', 'location_master_id']),
            'receipt_date' => $record->receipt_date->toDateString(), 'status' => $record->status->value, 'status_label' => $record->status->getLabel(),
            'purchase_order' => $record->purchaseOrder?->document_number, 'supplier' => $record->supplier?->name, 'location' => $record->location?->name,
            'approval_status' => $record->approval?->approval_status ?? 'draft', 'posted_at' => $record->posted_at?->toIso8601String(), 'posted_by' => $record->posted_by,
            'poster' => $record->postedBy?->name, 'can_edit' => $actor->can('update', $record) && $record->isEditable(),
            'actions' => collect(['submit', 'post', 'delete'])->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all(),
        ];
    }

    public function detail(User $actor, GoodsReceiptNote $record): array
    {
        $record->load(['lines.item' => fn (BelongsTo $query) => $query->withTrashed()]);

        return [...$this->summary($actor, $record), 'notes' => $record->notes, 'revision' => $this->revision($record),
            'lines' => $record->lines->map(fn (GoodsReceiptNoteLine $line): array => [
                ...$line->only(['id', 'purchase_order_line_id', 'item_master_id', 'quantity_received', 'unit_cost', 'batch_number', 'remarks']), 'item' => $line->item?->item_name,
            ])->values()->all(),
            'movements' => InventoryMovement::query()->where('reference_type', $record->getMorphClass())->where('reference_id', $record->id)->orderBy('id')->get()
                ->map(fn (InventoryMovement $movement): array => [...$movement->only(['id', 'item_master_id', 'location_master_id', 'movement_type', 'quantity_in', 'balance_after', 'unit_cost', 'total_value', 'remarks']), 'movement_at' => $movement->movement_at?->toIso8601String()])->all(),
        ];
    }

    public function save(User $actor, ?int $id, array $input): GoodsReceiptNote
    {
        $this->authorize($actor);
        $input = Validator::make($input, GoodsReceiptRegisterRequest::saveRules($id !== null))->validate();

        return (new GoodsReceiptNote)->getConnection()->transaction(function () use ($actor, $id, $input): GoodsReceiptNote {
            $record = $id === null ? new GoodsReceiptNote : $this->find($actor, $id, true);
            abort_unless($actor->can($id === null ? 'create' : 'update', $id === null ? GoodsReceiptNote::class : $record), 403);
            if ($id !== null) {
                $this->checkRevision($record, $input['revision']);
                abort_unless($record->isEditable(), 409, 'Pending, approved or posted receipts cannot be edited.');
            } else {
                NumberSeries::getNextNumber(GoodsReceiptNote::class);
                NumberSeries::query()->where('model_type', GoodsReceiptNote::class)->whereNull('type_master_id')->lockForUpdate()->firstOrFail();
            }
            $this->validateConfiguration($actor, $input);
            $lines = $record->lines()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($input['lines'] as $index => $row) {
                if (isset($row['id']) && ! $lines->has($row['id'])) {
                    throw ValidationException::withMessages(["lines.{$index}.id" => 'Choose a line from this receipt.']);
                }
            }
            $record->fill(Arr::only($input, ['purchase_order_id', 'supplier_id', 'location_master_id', 'receipt_date', 'notes']) + ['purchase_order_id' => null, 'notes' => null]);
            $record->save();
            foreach ($input['lines'] as $row) {
                $line = $lines->pull($row['id'] ?? 0) ?? $record->lines()->make();
                $line->fill(Arr::only($row, ['purchase_order_line_id', 'item_master_id', 'quantity_received', 'unit_cost', 'batch_number', 'remarks'])
                    + ['purchase_order_line_id' => null, 'batch_number' => null, 'remarks' => null]);
                $line->save();
            }
            foreach ($lines as $removed) {
                $removed->delete();
            }

            return $record->refresh();
        }, 3);
    }

    public function validateConfiguration(User $actor, array $input): void
    {
        foreach (['suppliers' => 'supplier_id', 'locations' => 'location_master_id'] as $type => $field) {
            if (! $this->catalog($actor, $type)->whereKey($input[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Select an available supplier and receiving location.']);
            }
        }
        $po = empty($input['purchase_order_id']) ? null : $this->purchaseOrder($actor, (int) $input['purchase_order_id'], true);
        if ($po !== null && ((int) $po->supplier_id !== (int) $input['supplier_id'] || (int) $po->location_master_id !== (int) $input['location_master_id'])) {
            throw ValidationException::withMessages(['purchase_order_id' => 'Keep the supplier and location from the selected purchase order.']);
        }
        $poLines = $po?->lines()->orderBy('id')->lockForUpdate()->get()->keyBy('id') ?? collect();
        $items = $this->catalog($actor, 'items')->whereKey(array_column($input['lines'], 'item_master_id'))->get()->keyBy('id');
        $requested = [];
        foreach ($input['lines'] as $index => $row) {
            if (! $items->has($row['item_master_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.item_master_id" => 'Select an available item.']);
            }
            if ((float) $row['quantity_received'] * (float) $row['unit_cost'] > 99999999999.9999) {
                throw ValidationException::withMessages(["lines.{$index}.unit_cost" => 'The receipt line value exceeds the supported amount.']);
            }
            if ($po === null) {
                if (! empty($row['purchase_order_line_id'])) {
                    throw ValidationException::withMessages(["lines.{$index}.purchase_order_line_id" => 'Select the purchase order for linked lines.']);
                }

                continue;
            }
            $line = $poLines->get($row['purchase_order_line_id'] ?? null);
            if ($line === null || (int) $line->item_master_id !== (int) $row['item_master_id']) {
                throw ValidationException::withMessages(["lines.{$index}.purchase_order_line_id" => 'Choose a matching line from the selected purchase order.']);
            }
            $requested[$line->id] = ($requested[$line->id] ?? 0) + (float) $row['quantity_received'];
            if (round($requested[$line->id], 3) > $line->remainingQuantity()) {
                throw ValidationException::withMessages(["lines.{$index}.quantity_received" => 'The receipt quantity exceeds the remaining purchase order quantity.']);
            }
        }
    }

    public function canAct(User $actor, GoodsReceiptNote $record, string $action): bool
    {
        if (! $actor->can(['ViewAny:GoodsReceiptNote', 'View:GoodsReceiptNote']) || $record->trashed()) {
            return false;
        }

        return match ($action) {
            'delete' => $actor->can('delete', $record) && $record->isEditable(),
            'submit' => $actor->can('update', $record) && $record->isEditable() && $record->lines()->exists() && $record->canStartApproval(),
            'post' => $actor->can('update', $record) && ! $record->isPosted() && $record->status === GoodsReceiptNoteStatus::Draft && $record->lines()->exists() && $record->canProceedAfterApproval(),
            default => false,
        };
    }

    public function act(User $actor, array $ids, string $action, ?string $revision = null, bool $bulk = false): void
    {
        $this->authorize($actor);
        (new GoodsReceiptNote)->getConnection()->transaction(function () use ($actor, $ids, $action, $revision, $bulk): void {
            if ($bulk) {
                abort_unless($action === 'delete' && $actor->can('deleteAny', GoodsReceiptNote::class), 403);
            }
            sort($ids);
            $records = collect($ids)->map(fn (int $id): GoodsReceiptNote => $this->find($actor, $id, true));
            foreach ($records as $record) {
                abort_unless($actor->can($action === 'delete' ? 'delete' : 'update', $record), 403);
                if (! $bulk) {
                    $this->checkRevision($record, (string) $revision);
                }
                abort_unless($this->canAct($actor, $record, $action), 409, 'This receipt action is no longer available. Reload the record.');
                if ($action !== 'delete') {
                    $this->validateConfiguration($actor, $record->only(['purchase_order_id', 'supplier_id', 'location_master_id'])
                        + ['lines' => $record->lines()->get()->map->getAttributes()->all()]);
                }
            }
            foreach ($records as $record) {
                try {
                    match ($action) {
                        'delete' => $record->delete(),
                        'submit' => $record->startApprovalFromRules(),
                        'post' => app(InventoryService::class)->postGrn($record, $actor->id),
                    };
                } catch (LogicException|RuntimeException $exception) {
                    throw ValidationException::withMessages([$action => $exception->getMessage()]);
                }
            }
        }, 3);
    }

    private function checkRevision(GoodsReceiptNote $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The receipt or linked PO changed. Reload it before continuing.');
    }
}
