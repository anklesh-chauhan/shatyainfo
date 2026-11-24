<?php

namespace App\Services\Field;

use App\Enums\DeliveryChallanStatus;
use App\Enums\SalesDocumentKind;
use App\Helpers\SalesDocumentHelper;
use App\Models\AccountMaster;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalOverride;
use App\Models\ApprovalSetting;
use App\Models\ContactDetail;
use App\Models\DeliveryChallan;
use App\Models\ItemMaster;
use App\Models\NumberSeries;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Quote;
use App\Models\SalesDocument;
use App\Models\SalesDocumentItem;
use App\Models\SalesDocumentPreference;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\ShippingMethod;
use App\Models\TermsAndConditionsMaster;
use App\Models\User;
use App\Services\Inventory\InvoiceDeliveryHandoff;
use App\Services\OptionalModuleService;
use App\Services\PositionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

class FieldSalesDocumentService
{
    public const STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'canceled'];

    private const RELATIONS = ['approval', 'accountMaster:id,name,territory_id', 'contactDetail', 'salesPerson:id,name'];

    private const EDITABLE_FIELDS = [
        'account_master_id', 'contact_detail_id', 'billing_address_id', 'shipping_address_id',
        'date', 'status', 'description', 'payment_term_id', 'currency', 'sales_person_id',
        'payment_method_id', 'shipping_method_id', 'discount_type', 'discount_value',
        'shipping_cost', 'packing_forwarding', 'insurance_charges', 'other_charges', 'round_off',
    ];

    public function __construct(
        private SalesDocumentPricing $pricing,
        private SalesDocumentKind $kind,
        private InvoiceDeliveryHandoff $delivery,
    ) {}

    private function visible(User $actor): Builder
    {
        $module = $this->kind->module();
        $model = $this->kind->model();
        abort_unless($actor->can(["ViewAny:{$module}", "View:{$module}"]), 403);

        return $model::query()->applyVisibility($module, $actor);
    }

    private function registerQuery(User $actor): Builder
    {
        $query = $this->visible($actor)->with(self::RELATIONS);
        if ($this->kind === SalesDocumentKind::Order) {
            $query->withExists([
                'fieldOrderState',
                'items',
                'salesInvoices as has_linked_invoice' => fn (Builder $invoices): Builder => $invoices->withTrashed(),
                'items as has_delivered_items' => fn (Builder $lines): Builder => $lines->where('quantity_delivered', '>', 0),
            ]);
        } elseif ($this->kind === SalesDocumentKind::Invoice) {
            $query->withExists([
                'items',
                'deliveryChallans as has_active_challan' => fn (Builder $challans): Builder => $challans
                    ->where('status', '!=', DeliveryChallanStatus::Cancelled->value),
                'items as has_delivered_items' => fn (Builder $lines): Builder => $lines->where('quantity_delivered', '>', 0),
            ]);
        }

        return $query;
    }

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        $module = $this->kind->module();
        $flows = $actor->can("Update:{$module}") && ApprovalSetting::moduleRequiresApproval($module)
            ? ApprovalFlow::query()->forModule($module)->where('active', true)->effectiveOn()->get()
            : collect();

        return $this->registerQuery($actor)
            ->when(filled($search), fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $match
                ->whereLike('document_number', '%'.$search.'%')
                ->when($this->kind !== SalesDocumentKind::Quote, fn (Builder $reference): Builder => $reference->orWhereLike('other_ref', '%'.$search.'%'))
                ->orWhereHas('accountMaster', fn (Builder $account): Builder => $account->whereLike('name', '%'.$search.'%'))
                ->orWhereHas('contactDetail', fn (Builder $contact): Builder => $contact
                    ->whereLike('first_name', '%'.$search.'%')->orWhereLike('last_name', '%'.$search.'%'))
                ->orWhereHas('salesPerson', fn (Builder $salesPerson): Builder => $salesPerson->whereLike('name', '%'.$search.'%'))))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['account_master_id'] ?? null, fn (Builder $query, $id): Builder => $query->where('account_master_id', $id))
            ->when($filters['contact_detail_id'] ?? null, fn (Builder $query, $id): Builder => $query->where('contact_detail_id', $id))
            ->when($filters['sales_person_id'] ?? null, fn (Builder $query, $id): Builder => $query->where('sales_person_id', $id))
            ->when(isset($filters['min_total']), fn (Builder $query): Builder => $query->where('total', '>=', $filters['min_total']))
            ->when(isset($filters['max_total']), fn (Builder $query): Builder => $query->where('total', '<=', $filters['max_total']))
            ->when($filters['sent'] ?? null, fn (Builder $query): Builder => $query->whereNotNull('sent_at'))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate('date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to): Builder => $query->whereDate('date', '<=', $to))
            ->orderByDesc('date')->orderByDesc('id')->paginate($filters['per_page'] ?? 50)
            ->through(fn (Quote|SalesOrder|SalesInvoice $document): array => $this->summary($document, $actor, $this->matchesFlow($document, $flows)));
    }

    public function find(User $actor, int $id): Quote|SalesOrder|SalesInvoice
    {
        return $this->registerQuery($actor)->findOrFail($id);
    }

    public function filterOptions(User $actor, string $field, array $input): LengthAwarePaginator
    {
        [$type, $names] = match ($field) {
            'account_master_id' => [AccountMaster::class, ['name']],
            'contact_detail_id' => [ContactDetail::class, ['first_name', 'last_name']],
            'sales_person_id' => [User::class, ['name']],
            default => abort(404),
        };
        $search = $input['search'] ?? null;

        return $type::query()->whereIn('id', $this->visible($actor)->select($field))
            ->when(filled($search), fn (Builder $query): Builder => $query->where(function (Builder $match) use ($names, $search): void {
                foreach ($names as $name) {
                    $match->orWhereLike($name, '%'.$search.'%');
                }
            }))
            ->orderBy($names[0])->orderBy('id')->paginate($input['per_page'] ?? 50)
            ->through(fn ($record): array => ['id' => $record->id, 'label' => $record->name ?? $record->full_name]);
    }

    public function options(User $actor): array
    {
        return [
            'can_create' => $actor->can('create', $this->kind->model()),
            'discount_mode' => SalesDocumentPreference::query()->first()?->discount_level ?? 'none',
            'payment_terms' => PaymentTerm::query()->orderBy('name')->get(['id', 'name']),
            'payment_methods' => PaymentMethod::query()->orderBy('name')->get(['id', 'name']),
            'shipping_methods' => ShippingMethod::query()->orderBy('name')->get(['id', 'name']),
            'default_terms' => $this->plainTerms(TermsAndConditionsMaster::query()
                ->where('document_type', strtolower($this->kind->module()))->where('is_default', true)->orderBy('id')->pluck('content')->implode("\n\n")),
            'sales_person_id' => $actor->id,
            'sales_person_name' => $actor->name,
        ];
    }

    public function items(User $actor, array $filters): LengthAwarePaginator
    {
        abort_unless($actor->can('create', $this->kind->model()) || $actor->can('Update:'.$this->kind->module()), 403);
        $search = $filters['search'] ?? null;

        return ItemMaster::query()->with('parent:id,item_name')
            ->when(filled($search), fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $match
                ->whereLike('item_name', '%'.$search.'%')->orWhereLike('item_code', '%'.$search.'%')
                ->orWhereLike('variant_name', '%'.$search.'%')))
            ->orderBy('item_name')->orderBy('id')->paginate($filters['per_page'] ?? 50)
            ->through(fn (ItemMaster $item): array => [
                'id' => $item->id,
                'label' => $item->parent && filled($item->variant_name)
                    ? $item->parent->item_name.' – '.$item->variant_name : $item->item_name,
            ]);
    }

    public function detail(Quote|SalesOrder|SalesInvoice $document, User $actor): array
    {
        $document->load(['items.itemMaster:id,item_name', 'billingAddress', 'shippingAddress', 'taxDetails', 'termsAndCondition']);
        if (! $document instanceof Quote) {
            $document->items_exists = $document->items->isNotEmpty();
        }
        $available = $document instanceof SalesInvoice && $this->delivery->canCreate($actor) && $this->delivery->isReady($document)
            ? $this->delivery->availableLines($document)
            : collect();
        $specific = $document instanceof SalesInvoice
            ? [
                'due_date' => $document->due_date?->toDateString(),
                'paid_at' => $document->paid_at?->toDateString(),
                'payment_status' => $document->getRawOriginal('payment_status'),
                'other_ref' => $document->other_ref,
                'linked_quotes' => $this->relatedDocuments($actor, $document->quotes(), Quote::class),
                'linked_orders' => $this->relatedDocuments($actor, $document->salesOrders(), SalesOrder::class),
                'linked_challans' => $this->visibleChallans($actor, $document),
                'can_create_delivery_challan' => $available->isNotEmpty(),
            ]
            : [
                ...($document instanceof SalesOrder ? [
                    'delivery_date' => $document->delivery_date?->toDateString(),
                    'order_confirmation_at' => $document->order_confirmation_at?->toDateString(),
                    'other_ref' => $document->other_ref,
                    'linked_quotes' => $this->relatedDocuments($actor, $document->quotes(), Quote::class),
                ] : [
                    'expiration_date' => $document->expiration_date?->toDateString(),
                    'accepted_at' => $document->accepted_at?->toDateString(),
                    'linked_orders' => $this->relatedDocuments($actor, $document->salesOrders(), SalesOrder::class),
                    'can_convert_order' => $actor->can('create', SalesOrder::class) && $this->canConvert($document),
                ]),
                'linked_invoices' => $this->relatedDocuments($actor, $document->salesInvoices(), SalesInvoice::class),
                'can_convert_invoice' => $actor->can('create', SalesInvoice::class) && $this->canConvert($document),
            ];

        return [
            ...$this->summary($document, $actor),
            ...$document->only(['payment_term_id', 'payment_method_id', 'shipping_method_id', 'discount_type', 'discount_value',
                'discount_mode', 'shipping_cost', 'packing_forwarding', 'insurance_charges', 'other_charges', 'round_off',
                'billing_address_id', 'shipping_address_id', 'transaction_discount', 'gross_total', 'subtotal', 'tax', 'description']),
            ...$specific,
            'billing_address' => $document->billingAddress?->street,
            'shipping_address' => $document->shippingAddress?->street,
            'terms' => $this->plainTerms($document->termsAndCondition?->content),
            'items' => $document->items->map(fn (SalesDocumentItem $line): array => [
                ...$line->only(['id', 'item_master_id', 'quantity', 'unit', 'unit_price', 'discount', 'tax_rate', 'amount', 'final_taxable_amount', 'description']),
                'name' => $line->itemMaster?->item_name,
                ...($document instanceof Quote ? [] : $line->only(['quantity_delivered'])),
                ...($document instanceof SalesInvoice ? [
                    'remaining_quantity' => $line->remainingQuantity(),
                    'available_to_dispatch' => $available->get($line->id)['quantity_delivered'] ?? 0,
                ] : []),
            ])->values(),
            'tax_details' => $document->taxDetails->map->only(['type', 'rate', 'amount'])->values(),
        ];
    }

    private function summary(Quote|SalesOrder|SalesInvoice $document, User $actor, ?bool $hasApplicableFlow = null): array
    {
        return [
            ...$document->only(['id', 'document_number', 'status', 'total', 'currency', 'account_master_id', 'contact_detail_id', 'sales_person_id']),
            'date' => $document->date?->toDateString(),
            'sent' => $document->sent_at !== null,
            'account_name' => $document->accountMaster?->name,
            'contact_name' => $document->contactDetail?->full_name,
            'sales_person_name' => $document->salesPerson?->name,
            'approval_status' => $document->approval?->approval_status ?? $document->approval_status,
            'can_update' => $this->canMutate($actor, $document, 'update'),
            'can_delete' => $this->canMutate($actor, $document, 'delete'),
            'can_submit' => $actor->can('update', $document) && $this->canSubmitDocument($document)
                && ($hasApplicableFlow === null
                ? $document->canStartApproval()
                : $hasApplicableFlow && $document->status !== 'canceled' && ! in_array($document->approval?->approval_status, ['pending', 'approved'], true)
                    && in_array($document->approval_status, [null, 'draft', 'created', 'pending', 'submitted', 'rejected'], true)),
        ];
    }

    private function matchesFlow(Quote|SalesOrder|SalesInvoice $document, Collection $flows): bool
    {
        $territory = $document instanceof SalesInvoice ? $document->territory_id : $document->accountMaster?->territory_id;
        $amount = $document->approvalAmount();

        return $flows->contains(fn (ApprovalFlow $flow): bool => ($flow->territory_id === null || $flow->territory_id === $territory)
            && ($flow->condition_type === 'unconditional'
                || (($flow->min_amount === null || (float) $flow->min_amount <= $amount)
                    && ($flow->max_amount === null || (float) $flow->max_amount >= $amount))));
    }

    public function save(User $actor, ?int $id, array $input): Quote|SalesOrder|SalesInvoice
    {
        return $actor->getConnection()->transaction(function () use ($actor, $id, $input): Quote|SalesOrder|SalesInvoice {
            if ($id === null) {
                $model = $this->kind->model();
                abort_unless($actor->can('create', $model), 403);
                $document = new $model([
                    'sales_person_id' => $actor->id,
                    'discount_mode' => SalesDocumentPreference::query()->first()?->discount_level ?? 'none',
                    'currency' => 'INR',
                    'status' => 'draft',
                ]);
            } else {
                $document = $this->lock($actor, [$id])->sole();
                $this->assertMutate($actor, $document, 'update');
            }

            $specificFields = match ($this->kind) {
                SalesDocumentKind::Order => ['delivery_date', 'order_confirmation_at', 'other_ref'],
                SalesDocumentKind::Invoice => ['due_date', 'paid_at', 'payment_status', 'other_ref'],
                SalesDocumentKind::Quote => ['expiration_date', 'accepted_at'],
            };
            $document->fill(array_intersect_key($input, array_flip([...self::EDITABLE_FIELDS, ...$specificFields])));
            if (array_key_exists('round_off', $input) && $input['round_off'] === null) {
                $document->round_off = 0;
            }
            if ($document->isDirty('status') && $document->status === 'sent' && $document->sent_at === null) {
                $document->sent_at = today();
            }
            if ($document->isDirty('sales_person_id') && (int) $document->sales_person_id !== $actor->id
                && ! $this->selectableSalesPeople($actor)->whereKey($document->sales_person_id)->exists()) {
                throw ValidationException::withMessages(['sales_person_id' => 'Select an available salesperson.']);
            }
            $this->validateRelationships($actor, $document);
            abort_if($document instanceof Quote && $document->expiration_date && $document->expiration_date->lt($document->date), 422, 'Expiration must be on or after the quote date.');

            abort_if($document instanceof SalesOrder && $document->isDirty(['date', 'delivery_date'])
                && $document->delivery_date?->lt($document->date), 422, 'Delivery must be on or after the order date.');

            abort_if($document instanceof SalesInvoice && $document->isDirty(['date', 'due_date'])
                && $document->due_date?->lt($document->date), 422, 'The due date must be on or after the invoice date.');

            $keepPrices = $document->exists && ! $document->isDirty('account_master_id');
            $reprice = array_key_exists('items', $input) || $document->isDirty([
                'account_master_id', 'billing_address_id', 'discount_type', 'discount_value',
                'shipping_cost', 'packing_forwarding', 'insurance_charges', 'other_charges', 'round_off',
            ]);
            if (! $document->exists) {
                $document->document_number = $this->nextNumber($document::class);
            } elseif ($document->isDirty() || $reprice || array_key_exists('terms', $input)) {
                $this->auditOverride($actor, $document, 'updated', $document->isDirty() || $reprice);
            }
            if (! $document->exists || (! $reprice && $document->isDirty())) {
                $document->save();
            }
            if ($reprice) {
                $this->pricing->apply($document, $input['items'] ?? $this->pricing->storedLines($document), $keepPrices);
            }
            if (array_key_exists('terms', $input)) {
                if (filled($input['terms'])) {
                    $text = str_replace(["\r\n", "\r"], "\n", $input['terms']);
                    $document->termsAndCondition()->updateOrCreate([], ['content' => str_replace("\n", '<br>', e($text))]);
                } else {
                    $document->termsAndCondition()->delete();
                }
            }

            return $document->refresh()->load(self::RELATIONS);
        }, attempts: 3);
    }

    public function act(User $actor, string $action, array $ids): array
    {
        return $actor->getConnection()->transaction(function () use ($actor, $action, $ids): array {
            $documents = $this->lock($actor, $ids);
            if ($action === 'delete') {
                foreach ($documents as $document) {
                    $this->assertMutate($actor, $document, 'delete');
                }
                foreach ($documents as $document) {
                    $this->auditOverride($actor, $document, 'deleted');
                    abort_unless($document->delete(), 409, 'Could not delete the selected '.$this->kind->label().'s.');
                }

                return ['deleted' => $documents->count()];
            }

            if ($action === 'submit') {
                foreach ($documents as $document) {
                    abort_unless($actor->can('update', $document), 403);
                    abort_unless($this->canSubmitDocument($document) && $document->canStartApproval(), 422,
                        'Approval is unavailable for one or more '.$this->kind->label().'s.');
                }
                try {
                    foreach ($documents as $document) {
                        $document->startApprovalFromRules();
                    }
                } catch (LogicException) {
                    throw ValidationException::withMessages(['ids' => 'Could not start an approval workflow for the selected '.$this->kind->label().'s.']);
                }

                return ['submitted' => $documents->count()];
            }

            abort_if($this->kind === SalesDocumentKind::Invoice, 422);
            $target = $action === 'sales_order' ? SalesOrder::class : SalesInvoice::class;

            return $this->convert($actor, $documents->sole(), $target);
        }, attempts: 3);
    }

    private function validateRelationships(User $actor, Quote|SalesOrder|SalesInvoice $document): void
    {
        if ($document->exists && ! $document->isDirty(['account_master_id', 'contact_detail_id', 'billing_address_id', 'shipping_address_id'])) {
            return;
        }

        $account = AccountMaster::query()->applyVisibility('AccountMaster', $actor)->find($document->account_master_id);
        if (! $account || ! $actor->can('viewAny', AccountMaster::class) || ! $actor->can('view', $account)) {
            throw ValidationException::withMessages(['account_master_id' => 'Select a visible customer.']);
        }
        $contact = $account->contactDetails()->whereKey($document->contact_detail_id)->first();
        if (! $contact) {
            throw ValidationException::withMessages(['contact_detail_id' => 'Select a contact linked to this customer.']);
        }
        foreach (['billing_address_id', 'shipping_address_id'] as $field) {
            if ($document->$field === null || $account->addresses()->whereKey($document->$field)->exists()) {
                continue;
            }
            $visibleContact = $actor->can('viewAny', ContactDetail::class) && $actor->can('view', $contact)
                && ContactDetail::query()->applyVisibility('ContactDetail', $actor)->whereKey($contact->id)->exists();
            if (! $visibleContact || ! $contact->addresses()->whereKey($document->$field)->exists()) {
                throw ValidationException::withMessages([$field => 'Select an address linked to this customer.']);
            }
        }
    }

    public function salesPeople(User $actor, array $filters): LengthAwarePaginator
    {
        abort_unless($actor->can('create', $this->kind->model()) || $actor->can('Update:'.$this->kind->module()), 403);
        $search = $filters['search'] ?? null;

        return $this->selectableSalesPeople($actor)
            ->when(filled($search), fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 50, ['id', 'name as label']);
    }

    private function selectableSalesPeople(User $actor): Builder
    {
        $query = User::query();
        if ($actor->hasAnyRole(['super_admin', 'administration_admin']) || $actor->can('AccessAllRecords')) {
            return $query;
        }

        return $query->whereKey(PositionService::getVisibleUserIdsFor($actor)->push($actor->id)->unique()->all());
    }

    /** @param class-string<SalesDocument> $type */
    private function relatedDocuments(User $actor, BelongsToMany $linked, string $type): Collection
    {
        if (! $actor->can(['ViewAny:'.class_basename($type), 'View:'.class_basename($type)])) {
            return collect();
        }

        return $linked->applyVisibility(class_basename($type), $actor)->get()
            ->map(fn (SalesDocument $document): array => $document->only(['id', 'document_number', 'status']));
    }

    private function visibleChallans(User $actor, SalesInvoice $invoice): Collection
    {
        if (! $actor->can(['ViewAny:DeliveryChallan', 'View:DeliveryChallan'])
            || ! app(OptionalModuleService::class)->navigationEnabled('InventoryStock')) {
            return collect();
        }

        return $invoice->deliveryChallans()->applyVisibility('DeliveryChallan', $actor)
            ->orderByDesc('id')->get()->map(fn (DeliveryChallan $challan): array => [
                'id' => $challan->id,
                'document_number' => $challan->document_number,
                'status' => $challan->status->value,
            ]);
    }

    private function plainTerms(mixed $content): ?string
    {
        return is_string($content)
            ? trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?\s*>|<\/(?:p|div|li)>/i', "\n", $content))))
            : null;
    }

    private function lock(User $actor, array $ids): Collection
    {
        $documents = $this->registerQuery($actor)->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($documents->count() === count($ids), 404, 'One or more '.$this->kind->label().'s are unavailable.');
        if ($this->kind === SalesDocumentKind::Invoice) {
            foreach ($documents as $invoice) {
                $invoice->has_active_challan = $invoice->deliveryChallans()
                    ->where('status', '!=', DeliveryChallanStatus::Cancelled->value)->exists();
                $invoice->has_delivered_items = $invoice->items()->where('quantity_delivered', '>', 0)->exists();
            }
        }
        if ($this->kind !== SalesDocumentKind::Quote) {
            return $documents;
        }

        $approvals = Approval::query()->whereKey($documents->pluck('approval.id')->filter()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($documents as $document) {
            $document->setRelation('approval', $approvals->get($document->approval?->id));
        }

        return $documents;
    }

    private function canMutate(User $actor, Quote|SalesOrder|SalesInvoice $document, string $ability): bool
    {
        if (! $document instanceof Quote) {
            return $actor->can($ability, $document) && ! $this->pending($document) && ! $this->approved($document)
                && ! $this->fulfilled($document)
                && (! $document instanceof SalesOrder || ! $document->field_order_state_exists);
        }

        return $actor->can($ability, $document)
            && ! $this->pending($document)
            && (! $this->approved($document) || $actor->can('overrideApproval', $document));
    }

    private function assertMutate(User $actor, Quote|SalesOrder|SalesInvoice $document, string $ability): void
    {
        abort_unless($actor->can($ability, $document), 403);
        abort_unless($this->canMutate($actor, $document, $ability), 422, 'This '.$this->kind->label().' is locked.');
    }

    private function pending(Quote|SalesOrder|SalesInvoice $document): bool
    {
        return $document->approval_status === 'pending' || $document->approval?->approval_status === 'pending';
    }

    private function approved(Quote|SalesOrder|SalesInvoice $document): bool
    {
        return $document->approval_status === 'approved' || $document->approval?->approval_status === 'approved';
    }

    private function auditOverride(User $actor, Quote|SalesOrder|SalesInvoice $document, string $action, bool $modelEvents = true): void
    {
        if ($document instanceof Quote && (! $modelEvents || $document->approval_status !== 'approved') && $this->approved($document)) {
            ApprovalOverride::query()->create([
                'model_type' => Quote::class, 'model_id' => $document->id, 'user_id' => $actor->id,
                'reason' => "Quote {$action} through the mobile API",
            ]);
        }
    }

    private function canConvert(Quote|SalesOrder $document): bool
    {
        if ($document instanceof SalesOrder) {
            return ! in_array($document->status, ['canceled', 'rejected'], true)
                && ! $this->pending($document) && ($document->has_linked_invoice || ($document->items_exists && ! $document->has_delivered_items))
                && $document->canProceedAfterApproval();
        }

        return $document->status !== 'canceled'
            && ! $this->pending($document)
            && ((! $document->approvalApplies() && $document->approval === null) || $this->approved($document));
    }

    private function canSubmitDocument(Quote|SalesOrder|SalesInvoice $document): bool
    {
        return $document instanceof Quote || ($document->items_exists && ! $this->fulfilled($document) && ! $this->approved($document));
    }

    private function fulfilled(Quote|SalesOrder|SalesInvoice $document): bool
    {
        return match (true) {
            $document instanceof SalesOrder => $document->has_linked_invoice || $document->has_delivered_items,
            $document instanceof SalesInvoice => $document->has_active_challan || $document->has_delivered_items,
            default => false,
        };
    }

    /** @param class-string<SalesDocument> $target */
    private function convert(User $actor, Quote|SalesOrder $document, string $target): array
    {
        abort_unless($actor->can('create', $target), 403);
        abort_unless($this->canConvert($document), 422, 'This '.$this->kind->label().' is unavailable for conversion.');
        $linked = $target === SalesOrder::class && $document instanceof Quote ? $document->salesOrders() : $document->salesInvoices();
        $converted = $linked->withTrashed()->orderBy($linked->getRelated()->getQualifiedKeyName())->first();
        $created = $converted === null;
        if ($created) {
            $converted = SalesDocumentHelper::createFrom($document, $target, ['document_number' => $this->nextNumber($target)]);
        } else {
            abort_if($converted->trashed(), 409, 'This '.$this->kind->label().' already has a linked document.');
        }

        $visible = $actor->can('viewAny', $target) && $actor->can('view', $converted)
            && $target::query()->applyVisibility(class_basename($target), $actor)->whereKey($converted->id)->exists();

        return [
            'created' => $created,
            'type' => $target === SalesOrder::class ? 'sales_order' : 'sales_invoice',
            'document' => $visible ? $converted->only(['id', 'document_number', 'status']) : null,
        ];
    }

    /** @param class-string<SalesDocument> $type */
    private function nextNumber(string $type): string
    {
        NumberSeries::getNextNumber($type);
        $series = NumberSeries::query()->where('model_type', $type)->whereNull('type_master_id')->lockForUpdate()->firstOrFail();

        return "{$series->Prefix}{$series->next_number}{$series->Suffix}";
    }
}
