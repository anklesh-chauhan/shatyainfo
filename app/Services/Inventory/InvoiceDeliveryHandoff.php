<?php

namespace App\Services\Inventory;

use App\Enums\DeliveryChallanStatus;
use App\Models\ApprovalSetting;
use App\Models\DeliveryChallan;
use App\Models\LocationMaster;
use App\Models\NumberSeries;
use App\Models\SalesDocumentItem;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\OptionalModuleService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InvoiceDeliveryHandoff
{
    public function __construct(private OptionalModuleService $modules) {}

    public function canCreate(User $actor): bool
    {
        return $this->canReadInvoices($actor)
            && $this->modules->navigationEnabled('InventoryStock')
            && $actor->can('create', DeliveryChallan::class);
    }

    public function authorizeCreate(User $actor): void
    {
        abort_unless($this->canCreate($actor), 403);
    }

    public function visibleInvoices(User $actor): Builder
    {
        return SalesInvoice::query()->applyVisibility('SalesInvoice', $actor)
            ->when(! $this->canReadInvoices($actor), fn (Builder $query): Builder => $query->whereKey([]));
    }

    public function selectableInvoices(User $actor): Builder
    {
        $required = ApprovalSetting::moduleRequiresApproval(SalesInvoice::class);

        return $this->visibleInvoices($actor)
            ->whereNotIn('status', ['canceled', 'rejected'])
            ->whereNotIn('approval_status', ['pending', 'rejected'])
            ->whereDoesntHave('approval', fn (Builder $approval): Builder => $approval->whereIn('approval_status', ['pending', 'rejected']))
            ->when($required, fn (Builder $query): Builder => $query->whereHas('approval',
                fn (Builder $approval): Builder => $approval->where('approval_status', 'approved')))
            ->whereHas('items', fn (Builder $items): Builder => $items->whereNotNull('item_master_id')->whereColumn('quantity', '>', 'quantity_delivered'));
    }

    public function isReady(SalesInvoice $invoice): bool
    {
        return ! in_array($invoice->status, ['canceled', 'rejected'], true)
            && ! in_array($invoice->approval_status, ['pending', 'rejected'], true)
            && ! in_array($invoice->approval?->approval_status, ['pending', 'rejected'], true)
            && $invoice->canProceedAfterApproval();
    }

    public function assertReady(SalesInvoice $invoice): void
    {
        abort_unless($this->isReady($invoice), 422, 'Complete invoice approval before creating a Delivery Challan.');
    }

    /** @return Collection<int, array{sales_document_item_id: int, item_master_id: int, quantity_delivered: float, unit_cost: mixed}> */
    public function availableLines(SalesInvoice $invoice, ?int $exceptDraft = null): Collection
    {
        return $invoice->items()->with('itemMaster')
            ->whereHas('itemMaster')
            ->withSum(['deliveryChallanLines as draft_quantity' => fn (Builder $lines): Builder => $lines
                ->whereHas('deliveryChallan', fn (Builder $challans): Builder => $challans
                    ->where('sales_invoice_id', $invoice->id)
                    ->where('status', DeliveryChallanStatus::Draft->value)
                    ->whereNull('posted_at')
                    ->when($exceptDraft !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptDraft)))], 'quantity_delivered')
            ->get()
            ->mapWithKeys(fn (SalesDocumentItem $line): array => [$line->id => [
                'sales_document_item_id' => $line->id,
                'item_master_id' => $line->item_master_id,
                'quantity_delivered' => round(max(0, $line->remainingQuantity() - (float) $line->draft_quantity), 3),
                'unit_cost' => $line->unit_price ?? $line->itemMaster->selling_price ?? 0,
            ]])
            ->filter(fn (array $line): bool => $line['quantity_delivered'] > 0);
    }

    public function locations(array $filters): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return LocationMaster::query()->applyVisibility('LocationMaster')->where('is_active', true)
            ->when(filled($search), fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match->whereLike('name', '%'.$search.'%')->orWhereLike('location_code', '%'.$search.'%')))
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 50, ['id', 'name', 'location_code'])
            ->through(fn (LocationMaster $location): array => ['id' => $location->id, 'label' => "{$location->name} [{$location->location_code}]"]);
    }

    public function create(User $actor, int $invoiceId, array $input): void
    {
        $this->authorizeCreate($actor);
        $actor->getConnection()->transaction(function () use ($actor, $invoiceId, $input): void {
            $invoice = $this->visibleInvoices($actor)->lockForUpdate()->findOrFail($invoiceId);
            $this->assertReady($invoice);

            if (! LocationMaster::query()->applyVisibility('LocationMaster')->where('is_active', true)->whereKey($input['location_master_id'])->exists()) {
                throw ValidationException::withMessages(['location_master_id' => 'Select an active dispatch location.']);
            }

            $available = $this->availableLines($invoice);
            $lines = collect($input['lines'])->map(function (array $inputLine) use ($available): array {
                $line = $available->get($inputLine['sales_document_item_id']);
                if ($line === null) {
                    throw ValidationException::withMessages(['lines' => 'Select invoice lines with quantity available to dispatch.']);
                }

                return [...$line, 'quantity_delivered' => $inputLine['quantity_delivered']];
            });
            $this->assertLinesFit($lines, $available);

            $challan = $invoice->deliveryChallans()->create([
                'customer_id' => $invoice->account_master_id,
                'location_master_id' => $input['location_master_id'],
                'delivery_date' => $input['delivery_date'],
                'notes' => $input['notes'] ?? null,
                'document_number' => $this->nextNumber(),
            ]);
            $challan->lines()->createMany($lines->all());
        }, attempts: 3);
    }

    public function assertLinesFit(Collection $lines, Collection $available): void
    {
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['data.lines' => 'Add at least one invoice line to deliver.']);
        }

        $quantities = [];
        foreach ($lines as $line) {
            $id = $line['sales_document_item_id'];
            $item = $available->get($id);
            $quantity = (float) $line['quantity_delivered'];
            $quantities[$id] = round(($quantities[$id] ?? 0) + $quantity, 3);
            if ($item === null || (int) $line['item_master_id'] !== (int) $item['item_master_id']
                || $quantity <= 0 || $quantities[$id] > $item['quantity_delivered']) {
                throw ValidationException::withMessages(['data.lines' => 'Delivery quantities must fit the available quantities on this invoice.']);
            }
        }
    }

    public function nextNumber(): string
    {
        NumberSeries::getNextNumber(DeliveryChallan::class);
        $series = NumberSeries::query()->where('model_type', DeliveryChallan::class)
            ->whereNull('type_master_id')->lockForUpdate()->firstOrFail();

        return "{$series->Prefix}{$series->next_number}{$series->Suffix}";
    }

    private function canReadInvoices(User $actor): bool
    {
        return $this->modules->navigationEnabled('SalesInvoice') && $actor->can(['ViewAny:SalesInvoice', 'View:SalesInvoice']);
    }
}
