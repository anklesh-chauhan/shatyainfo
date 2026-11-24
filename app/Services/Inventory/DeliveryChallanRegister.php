<?php

namespace App\Services\Inventory;

use App\Enums\DeliveryChallanStatus;
use App\Http\Requests\Api\V1\DeliveryChallanRegisterRequest;
use App\Models\AccountMaster;
use App\Models\DeliveryChallan;
use App\Models\DeliveryChallanLine;
use App\Models\InventoryMovement;
use App\Models\ItemMaster;
use App\Models\NumberSeries;
use App\Models\SalesDocumentItem;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\OptionalModuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

class DeliveryChallanRegister
{
    public function authorize(User $actor): void
    {
        abort_unless($actor->can(['ViewAny:DeliveryChallan', 'View:DeliveryChallan']), 403);
    }

    public function visible(User $actor): Builder
    {
        return DeliveryChallan::query()->applyVisibility('DeliveryChallan', $actor);
    }

    public function find(User $actor, int $id, bool $lock = false): DeliveryChallan
    {
        $this->authorize($actor);

        return $this->visible($actor)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
    }

    public function catalog(User $actor, string $type, ?int $invoiceId = null): Builder
    {
        if ($type === 'items' && $invoiceId !== null) {
            $invoice = app(InvoiceDeliveryHandoff::class)->visibleInvoices($actor)->find($invoiceId);

            return ItemMaster::query()->whereIn('id', $invoice?->items()->whereNotNull('item_master_id')->select('item_master_id') ?? []);
        }

        return match ($type) {
            'customers' => AccountMaster::query()->applyVisibility('AccountMaster', $actor),
            'locations', 'items' => app(PurchaseOrderRegister::class)->catalog($actor, $type),
            'sales-invoices' => app(InvoiceDeliveryHandoff::class)->selectableInvoices($actor)
                ->when(! app(OptionalModuleService::class)->navigationEnabled('InventoryStock'), fn (Builder $query): Builder => $query->whereKey([])),
        };
    }

    public function salesInvoice(User $actor, int $id, bool $lock = false): SalesInvoice
    {
        $this->authorize($actor);
        abort_unless(app(OptionalModuleService::class)->navigationEnabled('InventoryStock'), 403);
        $record = app(InvoiceDeliveryHandoff::class)->visibleInvoices($actor)
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
        app(InvoiceDeliveryHandoff::class)->assertReady($record);

        return $record;
    }

    public function orderData(User $actor, int $id, ?int $recordId = null): array
    {
        $record = $this->salesInvoice($actor, $id);
        if ($recordId !== null) {
            $challan = $this->find($actor, $recordId);
            abort_unless((int) $challan->sales_invoice_id === $record->id, 422);
        }
        $record->load('accountMaster');
        $available = app(InvoiceDeliveryHandoff::class)->availableLines($record, $recordId);
        $items = $record->items()->with('itemMaster')->get()->keyBy('id');

        return ['id' => $record->id, 'document_number' => $record->document_number,
            'customer_id' => $record->account_master_id, 'customer' => $record->accountMaster?->name,
            'lines' => $available->map(fn (array $line): array => [...$line, 'item' => $items->get($line['sales_document_item_id'])?->itemMaster?->item_name])->values()->all(),
        ];
    }

    public function orderLineOptions(User $actor, int $id): array
    {
        $invoice = app(InvoiceDeliveryHandoff::class)->visibleInvoices($actor)->find($id);

        return $invoice?->items()->whereNotNull('item_master_id')->with(['itemMaster' => fn (BelongsTo $query) => $query->withTrashed()])->get()
            ->mapWithKeys(fn (SalesDocumentItem $line): array => [$line->id => ($line->itemMaster?->item_name ?? 'Item').' (Remaining: '.$line->remainingQuantity().')'])->all() ?? [];
    }

    public function options(User $actor, string $type, string $search): array
    {
        $this->authorize($actor);
        $column = match ($type) {
            'items' => 'item_name', 'sales-invoices' => 'document_number', default => 'name'
        };
        $page = $this->catalog($actor, $type)->when($search !== '', fn (Builder $query): Builder => $query->whereLike($column, '%'.$search.'%'))
            ->orderBy($column)->orderBy('id')->paginate(30);

        return ['data' => $page->getCollection()->map(fn ($record): array => [
            'id' => $record->id, 'label' => $record->{$column}, ...($type === 'items' ? ['unit_cost' => $record->purchase_price] : []),
        ])->all(), 'meta' => ['last_page' => $page->lastPage()]];
    }

    public function revision(DeliveryChallan $record): string
    {
        $invoice = $record->salesInvoice()->first();

        return hash('sha256', json_encode(Arr::sortRecursive([
            $record->getAttributes(), $record->lines()->orderBy('id')->get()->map->getAttributes()->all(),
            $record->approval()->first()?->getAttributes(), $invoice?->getAttributes(),
            $invoice?->items()->orderBy('id')->get()->map->getAttributes()->all(),
            $invoice?->deliveryChallans()->with('lines')->orderBy('id')->get()->map(fn (DeliveryChallan $draft): array => [$draft->getAttributes(), $draft->lines->map->getAttributes()->all()])->all(),
        ]), JSON_THROW_ON_ERROR));
    }

    public function summary(User $actor, DeliveryChallan $record): array
    {
        $record->loadMissing(['salesInvoice' => fn (BelongsTo $query) => $query->withTrashed(),
            'customer' => fn (BelongsTo $query) => $query->withTrashed(), 'location' => fn (BelongsTo $query) => $query->withTrashed(), 'approval', 'postedBy']);

        return [...$record->only(['id', 'document_number', 'sales_invoice_id', 'customer_id', 'location_master_id']),
            'delivery_date' => $record->delivery_date->toDateString(), 'status' => $record->status->value, 'status_label' => $record->status->getLabel(),
            'sales_invoice' => $record->salesInvoice?->document_number, 'customer' => $record->customer?->name, 'location' => $record->location?->name,
            'approval_status' => $record->approval?->approval_status ?? 'draft', 'posted_at' => $record->posted_at?->toIso8601String(), 'posted_by' => $record->posted_by,
            'poster' => $record->postedBy?->name, 'can_edit' => $actor->can('update', $record) && $record->isEditable(),
            'actions' => collect(['submit', 'post', 'delete'])->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all(),
        ];
    }

    public function detail(User $actor, DeliveryChallan $record): array
    {
        $record->load(['lines.item' => fn (BelongsTo $query) => $query->withTrashed()]);

        return [...$this->summary($actor, $record), 'notes' => $record->notes, 'revision' => $this->revision($record),
            'lines' => $record->lines->map(fn (DeliveryChallanLine $line): array => [
                ...$line->only(['id', 'sales_document_item_id', 'item_master_id', 'quantity_delivered', 'unit_cost', 'batch_number', 'remarks']), 'item' => $line->item?->item_name,
            ])->values()->all(),
            'movements' => InventoryMovement::query()->where('reference_type', $record->getMorphClass())->where('reference_id', $record->id)->orderBy('id')->get()
                ->map(fn (InventoryMovement $movement): array => [...$movement->only(['id', 'item_master_id', 'location_master_id', 'movement_type', 'quantity_out', 'balance_after', 'unit_cost', 'total_value', 'remarks']), 'movement_at' => $movement->movement_at?->toIso8601String()])->all(),
        ];
    }

    public function save(User $actor, ?int $id, array $input): DeliveryChallan
    {
        $this->authorize($actor);
        $input = Validator::make($input, DeliveryChallanRegisterRequest::saveRules($id !== null))->validate();

        return (new DeliveryChallan)->getConnection()->transaction(function () use ($actor, $id, $input): DeliveryChallan {
            $record = $id === null ? new DeliveryChallan : $this->find($actor, $id, true);
            abort_unless($actor->can($id === null ? 'create' : 'update', $id === null ? DeliveryChallan::class : $record), 403);
            if ($id !== null) {
                $this->checkRevision($record, $input['revision']);
                abort_unless($record->isEditable(), 409, 'Pending, approved or posted challans cannot be edited.');
            } else {
                NumberSeries::getNextNumber(DeliveryChallan::class);
                NumberSeries::query()->where('model_type', DeliveryChallan::class)->whereNull('type_master_id')->lockForUpdate()->firstOrFail();
            }
            $this->validateConfiguration($actor, $input, $id);
            $lines = $record->lines()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($input['lines'] as $index => $row) {
                if (isset($row['id']) && ! $lines->has($row['id'])) {
                    throw ValidationException::withMessages(["lines.{$index}.id" => 'Choose a line from this challan.']);
                }
            }
            $record->fill(Arr::only($input, ['sales_invoice_id', 'customer_id', 'location_master_id', 'delivery_date', 'notes']) + ['sales_invoice_id' => null, 'notes' => null]);
            $record->save();
            foreach ($input['lines'] as $row) {
                $line = $lines->pull($row['id'] ?? 0) ?? $record->lines()->make();
                $line->fill(Arr::only($row, ['sales_document_item_id', 'item_master_id', 'quantity_delivered', 'unit_cost', 'batch_number', 'remarks'])
                    + ['sales_document_item_id' => null, 'batch_number' => null, 'remarks' => null]);
                $line->save();
            }
            foreach ($lines as $removed) {
                $removed->delete();
            }

            return $record->refresh();
        }, 3);
    }

    public function validateConfiguration(User $actor, array $input, ?int $recordId = null): void
    {
        foreach (['customers' => 'customer_id', 'locations' => 'location_master_id'] as $type => $field) {
            if (! $this->catalog($actor, $type)->whereKey($input[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Select an available customer and dispatch location.']);
            }
        }
        $invoice = empty($input['sales_invoice_id']) ? null : $this->salesInvoice($actor, (int) $input['sales_invoice_id'], true);
        if ($invoice !== null && (int) $invoice->account_master_id !== (int) $input['customer_id']) {
            throw ValidationException::withMessages(['sales_invoice_id' => 'Keep the customer from the selected invoice.']);
        }
        $invoiceLines = $invoice?->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id') ?? collect();
        $available = $invoice === null ? collect() : app(InvoiceDeliveryHandoff::class)->availableLines($invoice, $recordId);
        $items = $this->catalog($actor, 'items', $invoice?->id)->whereKey(array_column($input['lines'], 'item_master_id'))->get()->keyBy('id');
        $requested = [];
        foreach ($input['lines'] as $index => $row) {
            if (! $items->has($row['item_master_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.item_master_id" => 'Select an available item.']);
            }
            if ((float) $row['quantity_delivered'] * (float) $row['unit_cost'] > 99999999999.9999) {
                throw ValidationException::withMessages(["lines.{$index}.unit_cost" => 'The challan line value exceeds the supported amount.']);
            }
            if ($invoice === null) {
                if (! empty($row['sales_document_item_id'])) {
                    throw ValidationException::withMessages(["lines.{$index}.sales_document_item_id" => 'Select the sales invoice for linked lines.']);
                }

                continue;
            }
            $line = $invoiceLines->get($row['sales_document_item_id'] ?? null);
            if ($line === null || (int) $line->item_master_id !== (int) $row['item_master_id']) {
                throw ValidationException::withMessages(["lines.{$index}.sales_document_item_id" => 'Choose a matching line from the selected sales invoice.']);
            }
            $requested[$line->id] = ($requested[$line->id] ?? 0) + (float) $row['quantity_delivered'];
            if (round($requested[$line->id], 3) > (float) ($available->get($line->id)['quantity_delivered'] ?? 0)) {
                throw ValidationException::withMessages(["lines.{$index}.quantity_delivered" => 'The challan quantity exceeds the remaining sales invoice quantity.']);
            }
        }
    }

    public function canAct(User $actor, DeliveryChallan $record, string $action): bool
    {
        if (! $actor->can(['ViewAny:DeliveryChallan', 'View:DeliveryChallan']) || $record->trashed()) {
            return false;
        }

        return match ($action) {
            'delete' => $actor->can('delete', $record) && $record->isEditable(),
            'submit' => $actor->can('update', $record) && $record->isEditable() && $record->lines()->exists() && $record->canStartApproval(),
            'post' => $actor->can('update', $record) && ! $record->isPosted() && $record->status === DeliveryChallanStatus::Draft && $record->lines()->exists() && $record->canProceedAfterApproval(),
            default => false,
        };
    }

    public function act(User $actor, array $ids, string $action, ?string $revision = null, bool $bulk = false): void
    {
        $this->authorize($actor);
        (new DeliveryChallan)->getConnection()->transaction(function () use ($actor, $ids, $action, $revision, $bulk): void {
            if ($bulk) {
                abort_unless($action === 'delete' && $actor->can('deleteAny', DeliveryChallan::class), 403);
            }
            sort($ids);
            $records = collect($ids)->map(fn (int $id): DeliveryChallan => $this->find($actor, $id, true));
            foreach ($records as $record) {
                abort_unless($actor->can($action === 'delete' ? 'delete' : 'update', $record), 403);
                if (! $bulk) {
                    $this->checkRevision($record, (string) $revision);
                }
                abort_unless($this->canAct($actor, $record, $action), 409, 'This challan action is no longer available. Reload the record.');
                if ($action !== 'delete') {
                    $this->validateConfiguration($actor, $record->only(['sales_invoice_id', 'customer_id', 'location_master_id'])
                        + ['lines' => $record->lines()->get()->map->getAttributes()->all()], $record->id);
                }
            }
            foreach ($records as $record) {
                try {
                    match ($action) {
                        'delete' => $record->delete(),
                        'submit' => $record->startApprovalFromRules(),
                        'post' => app(InventoryService::class)->postDeliveryChallan($record, $actor->id),
                    };
                } catch (LogicException|RuntimeException $exception) {
                    throw ValidationException::withMessages([$action => $exception->getMessage()]);
                }
            }
        }, 3);
    }

    private function checkRevision(DeliveryChallan $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The challan or linked invoice changed. Reload it before continuing.');
    }
}
