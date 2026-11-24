<?php

namespace App\Services\Inventory;

use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\Api\V1\PurchaseOrderRegisterRequest;
use App\Models\AccountMaster;
use App\Models\ApprovalSetting;
use App\Models\GoodsReceiptNote;
use App\Models\ItemMaster;
use App\Models\LocationMaster;
use App\Models\NumberSeries;
use App\Models\PaymentTerm;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\OptionalModuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class PurchaseOrderRegister
{
    public function authorize(User $actor): void
    {
        abort_unless($actor->can(['ViewAny:PurchaseOrder', 'View:PurchaseOrder']), 403);
    }

    public function visible(User $actor): Builder
    {
        return PurchaseOrder::query()->applyVisibility('PurchaseOrder', $actor);
    }

    public function find(User $actor, int $id, bool $lock = false): PurchaseOrder
    {
        $this->authorize($actor);

        return $this->visible($actor)->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function catalog(User $actor, string $type): Builder
    {
        return match ($type) {
            'suppliers' => AccountMaster::query()->applyVisibility('AccountMaster', $actor)
                ->whereHas('typeMaster', fn (Builder $query) => $query->where('name', 'Vendor')
                    ->orWhereHas('parent', fn (Builder $parent) => $parent->where('name', 'Vendor'))),
            'locations' => LocationMaster::query()->applyVisibility('LocationMaster', $actor)->where('is_active', true),
            'items' => ItemMaster::query()->applyVisibility('ItemMaster', $actor)->whereNull('parent_id'),
            'payment-terms' => PaymentTerm::query(),
        };
    }

    public function options(User $actor, string $type, string $search): array
    {
        $this->authorize($actor);
        $column = $type === 'items' ? 'item_name' : 'name';
        $page = $this->catalog($actor, $type)->when($search !== '', fn (Builder $query) => $query->whereLike($column, '%'.$search.'%'))
            ->orderBy($column)->orderBy('id')->paginate(30);

        return ['data' => $page->getCollection()->map(fn ($record): array => [
            'id' => $record->id, 'label' => $record->{$column},
            ...($type === 'items' ? ['unit_price' => $record->purchase_price, 'tax_rate' => $record->tax_rate] : []),
        ])->all(), 'meta' => ['last_page' => $page->lastPage()]];
    }

    public function revision(PurchaseOrder $record): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive([
            $record->getAttributes(), $record->lines()->orderBy('id')->get()->map->getAttributes()->all(),
            $record->approval()->first()?->getAttributes(),
            $record->goodsReceiptNotes()->withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
        ]), JSON_THROW_ON_ERROR));
    }

    public function summary(User $actor, PurchaseOrder $record): array
    {
        $record->loadMissing([
            'supplier' => fn (BelongsTo $query) => $query->withTrashed(),
            'location' => fn (BelongsTo $query) => $query->withTrashed(),
        ]);

        return [
            ...$record->only(['id', 'document_number', 'supplier_id', 'location_master_id', 'currency', 'subtotal', 'tax_amount', 'total_amount', 'approval_status']),
            'order_date' => $record->order_date->toDateString(),
            'expected_delivery_date' => $record->expected_delivery_date?->toDateString(),
            'supplier' => $record->supplier?->name, 'location' => $record->location?->name,
            'status' => $record->status->value, 'status_label' => $record->status->getLabel(),
            'can_edit' => $actor->can('update', $record) && $record->isEditable(),
            'actions' => collect(['submit', 'approve', 'create-grn', 'cancel', 'delete'])
                ->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all(),
        ];
    }

    public function detail(User $actor, PurchaseOrder $record): array
    {
        $record->load(['paymentTerm', 'lines.item' => fn (BelongsTo $query) => $query->withTrashed()]);
        $receipts = $actor->can(['ViewAny:GoodsReceiptNote', 'View:GoodsReceiptNote'])
            && app(OptionalModuleService::class)->navigationEnabled('GoodsReceiptNote')
            ? $record->goodsReceiptNotes()->applyVisibility('GoodsReceiptNote', $actor)->with(['lines.item' => fn (BelongsTo $query) => $query->withTrashed()])->orderByDesc('id')->get() : collect();

        return [...$this->summary($actor, $record),
            'notes' => $record->notes, 'payment_term_id' => $record->payment_term_id, 'payment_term' => $record->paymentTerm?->name,
            'revision' => $this->revision($record),
            'lines' => $record->lines->map(fn (PurchaseOrderLine $line): array => [
                ...$line->only(['id', 'item_master_id', 'quantity_ordered', 'quantity_received', 'unit_price', 'tax_rate', 'line_total', 'description']),
                'item' => $line->item?->item_name, 'remaining_quantity' => $line->remainingQuantity(),
            ])->values()->all(),
            'receipts' => $receipts->map(fn (GoodsReceiptNote $receipt): array => [
                ...$receipt->only(['id', 'document_number', 'notes']), 'receipt_date' => $receipt->receipt_date->toDateString(),
                'status' => $receipt->status->value, 'posted_at' => $receipt->posted_at?->toIso8601String(),
                'lines' => $receipt->lines->map(fn ($line): array => [...$line->only(['item_master_id', 'quantity_received', 'unit_cost']), 'item' => $line->item?->item_name])->all(),
            ])->all(),
        ];
    }

    public function save(User $actor, ?int $id, array $input): PurchaseOrder
    {
        $this->authorize($actor);
        $input = Validator::make($input, PurchaseOrderRegisterRequest::saveRules($id !== null))->validate();

        return (new PurchaseOrder)->getConnection()->transaction(function () use ($actor, $id, $input): PurchaseOrder {
            $record = $id === null ? new PurchaseOrder : $this->find($actor, $id, true);
            abort_unless($actor->can($id === null ? 'create' : 'update', $id === null ? PurchaseOrder::class : $record), 403);
            if ($id !== null) {
                $this->checkRevision($record, $input['revision']);
                abort_unless($record->isEditable(), 409, 'Approved, received or pending purchase orders cannot be edited.');
            } else {
                $this->lockNumber(PurchaseOrder::class);
            }
            foreach (['suppliers' => 'supplier_id', 'locations' => 'location_master_id', 'payment-terms' => 'payment_term_id'] as $type => $field) {
                if (! empty($input[$field]) && ! $this->catalog($actor, $type)->whereKey($input[$field])->exists()) {
                    throw ValidationException::withMessages([$field => 'Select an available '.str_replace('_id', '', $field).'.']);
                }
            }
            $lines = $record->lines()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($input['lines'] as $index => $row) {
                if ((isset($row['id']) && ! $lines->has($row['id'])) || ! $this->catalog($actor, 'items')->whereKey($row['item_master_id'])->exists()) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'Select an available item and a line belonging to this purchase order.']);
                }
            }
            $total = collect($input['lines'])->sum(fn (array $row): float => round((float) $row['quantity_ordered'] * (float) $row['unit_price'], 2) * (1 + (float) $row['tax_rate'] / 100));
            if ($total > 9999999999999.99) {
                throw ValidationException::withMessages(['lines' => 'The purchase order total exceeds the supported amount.']);
            }
            $record->fill(Arr::only($input, ['supplier_id', 'location_master_id', 'order_date', 'expected_delivery_date', 'currency', 'payment_term_id', 'notes'])
                + ['expected_delivery_date' => null, 'payment_term_id' => null, 'notes' => null]);
            $record->save();
            foreach ($input['lines'] as $row) {
                $line = $lines->pull($row['id'] ?? 0) ?? $record->lines()->make();
                $line->fill(Arr::only($row, ['item_master_id', 'quantity_ordered', 'unit_price', 'tax_rate', 'description']) + ['description' => null]);
                $line->save();
            }
            foreach ($lines as $line) {
                abort_if($line->goodsReceiptNoteLines()->withTrashed()->exists(), 409, 'Lines with receipt history must be retained.');
                $line->delete();
            }
            $record->unsetRelation('lines');
            $record->recalculateTotals();

            return $record->refresh();
        }, 3);
    }

    public function canAct(User $actor, PurchaseOrder $record, string $action): bool
    {
        if (! $actor->can(['ViewAny:PurchaseOrder', 'View:PurchaseOrder']) || $record->trashed()) {
            return false;
        }

        return match ($action) {
            'cancel' => $actor->can('update', $record) && $record->isEditable(),
            'delete' => $actor->can('delete', $record) && $record->isEditable() && ! $record->goodsReceiptNotes()->withTrashed()->exists(),
            'submit' => $actor->can('update', $record) && $record->isEditable() && $record->canStartApproval() && $record->lines()->exists(),
            'approve' => $actor->can('update', $record) && $record->isEditable() && ! ApprovalSetting::moduleRequiresApproval(PurchaseOrder::class) && $record->lines()->exists(),
            'create-grn' => $actor->can('update', $record) && $actor->can(['Create:GoodsReceiptNote', 'ViewAny:GoodsReceiptNote', 'View:GoodsReceiptNote'])
                && app(OptionalModuleService::class)->navigationEnabled('GoodsReceiptNote')
                && in_array($record->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived], true)
                && $record->canProceedAfterApproval() && $record->lines()->whereColumn('quantity_ordered', '>', 'quantity_received')->exists(),
            default => false,
        };
    }

    public function act(User $actor, array $ids, string $action, ?string $revision = null, bool $bulk = false): ?GoodsReceiptNote
    {
        $this->authorize($actor);

        return (new PurchaseOrder)->getConnection()->transaction(function () use ($actor, $ids, $action, $revision, $bulk): ?GoodsReceiptNote {
            if ($bulk) {
                abort_unless(in_array($action, ['submit', 'delete'], true), 422);
                if ($action === 'delete') {
                    abort_unless($actor->can('deleteAny', PurchaseOrder::class), 403);
                }
            }
            sort($ids);
            $records = collect($ids)->map(fn (int $id): PurchaseOrder => $this->find($actor, $id, true));
            foreach ($records as $record) {
                abort_unless($actor->can($action === 'delete' ? 'delete' : 'update', $record), 403);
                if ($action === 'create-grn') {
                    abort_unless($actor->can(['Create:GoodsReceiptNote', 'ViewAny:GoodsReceiptNote', 'View:GoodsReceiptNote']) && app(OptionalModuleService::class)->navigationEnabled('GoodsReceiptNote'), 403);
                }
                if (! $bulk) {
                    $this->checkRevision($record, (string) $revision);
                }
                abort_unless($this->canAct($actor, $record, $action), 409, 'This purchase order action is no longer available. Reload the record.');
            }
            foreach ($records as $record) {
                if ($action === 'delete') {
                    $record->delete();
                } elseif ($action === 'cancel') {
                    $record->forceFill(['status' => PurchaseOrderStatus::Cancelled])->save();
                } elseif ($action === 'submit') {
                    try {
                        $record->startApprovalFromRules();
                    } catch (LogicException $exception) {
                        throw ValidationException::withMessages(['approval' => $exception->getMessage()]);
                    }
                } elseif ($action === 'approve') {
                    $record->assertApprovalComplete();
                    $record->forceFill(['status' => PurchaseOrderStatus::Approved, 'approval_status' => 'approved'])->save();
                } else {
                    return $this->createReceipt($actor, $record);
                }
            }

            return null;
        }, 3);
    }

    private function createReceipt(User $actor, PurchaseOrder $record): GoodsReceiptNote
    {
        foreach (['suppliers' => $record->supplier_id, 'locations' => $record->location_master_id] as $type => $id) {
            abort_unless($this->catalog($actor, $type)->whereKey($id)->exists(), 422, 'The supplier or receiving location is no longer available.');
        }
        $existing = $record->goodsReceiptNotes()->whereNull('posted_at')->where('status', 'draft')->first();
        if ($existing !== null) {
            abort_unless(GoodsReceiptNote::query()->applyVisibility('GoodsReceiptNote', $actor)->whereKey($existing->id)->exists(), 403);

            return $existing;
        }
        $lines = $record->lines()->whereColumn('quantity_ordered', '>', 'quantity_received')->orderBy('id')->lockForUpdate()->get();
        foreach ($lines as $line) {
            abort_unless($this->catalog($actor, 'items')->whereKey($line->item_master_id)->exists(), 422, 'A remaining item is no longer available.');
        }
        abort_if($lines->isEmpty(), 409, 'Nothing remains to receive.');
        $this->lockNumber(GoodsReceiptNote::class);
        $receipt = $record->goodsReceiptNotes()->create([
            'supplier_id' => $record->supplier_id, 'location_master_id' => $record->location_master_id, 'receipt_date' => today()->toDateString(),
        ]);
        foreach ($lines as $line) {
            $receipt->lines()->create(['purchase_order_line_id' => $line->id, 'item_master_id' => $line->item_master_id,
                'quantity_received' => $line->remainingQuantity(), 'unit_cost' => $line->unit_price]);
        }

        return $receipt;
    }

    private function checkRevision(PurchaseOrder $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The purchase order changed. Reload it before continuing.');
    }

    private function lockNumber(string $model): void
    {
        NumberSeries::getNextNumber($model);
        NumberSeries::query()->where('model_type', $model)->whereNull('type_master_id')->lockForUpdate()->firstOrFail();
    }
}
