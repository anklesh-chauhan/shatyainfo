<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCampaignStatus;
use App\Models\AccountMaster;
use App\Models\ApprovalSetting;
use App\Models\ItemMaster;
use App\Models\MarketingCampaign;
use App\Models\SampleIssue;
use App\Models\SgipDistribution;
use App\Models\SgipDistributionItem;
use App\Models\Territory;
use App\Models\User;
use App\Models\VisitPreference;
use App\Services\Field\FieldSgipStockService;
use App\Services\Inventory\InventoryService;
use App\Services\OptionalModuleService;
use App\Services\SGIPComplianceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SgipRegister
{
    public const RELATIONS = ['user', 'doctor', 'territory', 'marketingCampaign', 'sampleIssue', 'inventorySourceLocation'];

    public function authorizeRegister(User $actor): void
    {
        abort_unless(app(OptionalModuleService::class)->navigationEnabled('SgipDistribution')
            && $actor->can('viewAny', SgipDistribution::class), 403);
    }

    public function visible(User $actor): Builder
    {
        return SgipDistribution::query()->applyVisibility('SgipDistribution', $actor);
    }

    public function find(User $actor, int $id, bool $lock = false): SgipDistribution
    {
        $this->authorizeRegister($actor);
        $record = $this->visible($actor)->withTrashed()->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
        abort_unless($actor->can('view', $record), 403);

        return $record;
    }

    public function catalog(User $actor, string $type, ?SgipDistribution $record = null): Builder
    {
        if ($type === 'issues') {
            $employeeId = $record?->employee_id ?? $actor->employee?->id;

            return SampleIssue::query()->whereNotNull('posted_at')->where('status', 'posted')
                ->whereHas('sampleRequest', fn (Builder $query) => $query->where('employee_id', $employeeId)->whereNotNull('employee_id'));
        }
        [$model] = match ($type) {
            'doctors' => [AccountMaster::class],
            'territories' => [Territory::class],
            'campaigns' => [MarketingCampaign::class],
            'items' => [ItemMaster::class],
        };
        $query = $model::query()->applyVisibility(class_basename($model), $actor);

        return match ($type) {
            'doctors' => $query->whereHas('typeMaster', fn (Builder $query) => $query->whereIn('name', ['Doctor', 'Retail Customer'])),
            'campaigns' => $query->where('status', MarketingCampaignStatus::Active)
                ->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today()),
            'items' => $query->whereNotNull('item_type'),
            default => $query,
        };
    }

    public function options(User $actor, string $type, string $search, ?int $id = null): array
    {
        $this->authorizeRegister($actor);
        $record = $id === null ? null : $this->find($actor, $id);
        abort_unless($record === null ? $actor->can('create', SgipDistribution::class) : $actor->can('update', $record), 403);
        $column = match ($type) {
            'issues' => 'document_number', 'items' => 'item_name', default => 'name'
        };
        $page = $this->catalog($actor, $type, $record)
            ->when($search !== '', fn (Builder $query) => $query->whereLike($column, '%'.$search.'%'))
            ->orderBy($column)->orderBy('id')->paginate(30);

        return ['data' => $page->getCollection()->map(fn ($row): array => [
            'id' => $row->id, 'label' => $row->{$column},
            ...($type === 'items' ? ['unit_value' => $row->selling_price ?? 0] : []),
        ])->all(), 'meta' => ['last_page' => $page->lastPage()]];
    }

    public function summary(User $actor, SgipDistribution $record): array
    {
        return [
            ...$record->only(['id', 'visit_id', 'user_id', 'employee_id', 'account_master_id', 'territory_id', 'sample_issue_id', 'marketing_campaign_id', 'total_value', 'approval_status']),
            'document_number' => 'SGIP-'.$record->id,
            'visit_date' => $record->visit_date?->toDateString(),
            'customer' => $record->doctor?->name,
            'representative' => $record->user?->name,
            'territory' => $record->territory?->name,
            'campaign' => $record->marketingCampaign?->name,
            'sample_issue' => $record->sampleIssue?->document_number,
            'status' => $record->approval_status,
            'status_label' => ucfirst($record->approval_status),
            'trashed' => $record->trashed(),
            'can_view' => $actor->can('view', $record),
            'trash_actions' => collect(['delete', 'restore', 'force-delete'])
                ->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all(),
        ];
    }

    public function detail(User $actor, SgipDistribution $record): array
    {
        $record->loadMissing([...self::RELATIONS, 'items.item', 'violations']);

        return [
            ...$this->summary($actor, $record),
            'revision' => $this->revision($record),
            'can_edit' => $record->isEditable() && $actor->can('update', $record),
            'approval_required' => ApprovalSetting::moduleRequiresApproval(SgipDistribution::class),
            'source_mode' => VisitPreference::current()->sgip_stock_source?->value,
            'inventory_reserved_at' => $record->inventory_reserved_at?->toIso8601String(),
            'inventory_posted_at' => $record->inventory_posted_at?->toIso8601String(),
            'source' => $record->inventorySourceLocation?->name,
            'actions' => collect(['submit', 'approve', 'return', 'delete', 'restore', 'force-delete'])
                ->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all(),
            'items' => $record->items->map(fn (SgipDistributionItem $line): array => [
                ...$line->only(['id', 'item_master_id', 'quantity', 'unit_value', 'total_value']),
                'item' => $line->item?->item_name,
            ])->values()->all(),
            'violations' => $record->violations->map->only(['violation_type', 'allowed_value', 'actual_value'])->values()->all(),
        ];
    }

    public function revision(SgipDistribution $record): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive([
            $record->getAttributes(), $record->items()->orderBy('id')->get()->map->getAttributes()->all(),
        ]), JSON_THROW_ON_ERROR));
    }

    public function save(User $actor, ?int $id, array $input): SgipDistribution
    {
        $this->authorizeRegister($actor);

        return (new SgipDistribution)->getConnection()->transaction(function () use ($actor, $id, $input): SgipDistribution {
            $record = $id === null ? new SgipDistribution : $this->find($actor, $id, lock: true);
            if ($id === null) {
                abort_unless($actor->can('create', SgipDistribution::class), 403);
                if ($actor->employee === null) {
                    throw ValidationException::withMessages(['employee_id' => 'Link an employee to the signed-in user before creating a distribution.']);
                }
                $record->fill(['user_id' => $actor->id, 'employee_id' => $actor->employee->id, 'approval_status' => 'draft']);
            } else {
                abort_unless($actor->can('update', $record), 403);
                abort_unless($record->isEditable(), 409, 'Only active unreserved draft distributions can be edited.');
                $this->checkRevision($record, $input['revision']);
            }
            foreach (['account_master_id' => 'doctors', 'territory_id' => 'territories', 'marketing_campaign_id' => 'campaigns', 'sample_issue_id' => 'issues'] as $field => $type) {
                $value = $input[$field] ?? null;
                if ($value !== null && (int) $value !== (int) $record->{$field}
                    && ! $this->catalog($actor, $type, $record)->whereKey($value)->exists()) {
                    throw ValidationException::withMessages([$field => 'Select an available option.']);
                }
            }
            if ($record->visit_id !== null) {
                foreach (['account_master_id', 'territory_id', 'visit_date'] as $field) {
                    $current = $field === 'visit_date' ? $record->visit_date->toDateString() : $record->{$field};
                    if (($input[$field] ?? null) != $current) {
                        throw ValidationException::withMessages([$field => 'Visit-linked customer, territory and date must be retained.']);
                    }
                }
            }
            $record->fill(Arr::only($input, ['account_master_id', 'territory_id', 'visit_date', 'sample_issue_id', 'marketing_campaign_id'])
                + ['territory_id' => null, 'sample_issue_id' => null, 'marketing_campaign_id' => null]);
            abort_unless($record->save(), 409, 'Could not save the distribution.');
            $existingItems = $record->items()->pluck('item_master_id')->all();
            $totalValue = 0;
            foreach ($input['items'] as $index => $row) {
                $retainedItem = in_array((int) $row['item_master_id'], $existingItems, true)
                    && ItemMaster::query()->whereKey($row['item_master_id'])->whereNotNull('item_type')->exists();
                if (! $retainedItem && ! $this->catalog($actor, 'items', $record)->whereKey($row['item_master_id'])->exists()) {
                    throw ValidationException::withMessages(["items.{$index}.item_master_id" => 'Choose an available classified SGIP item.']);
                }
                $totalValue += round((float) $row['quantity'] * (float) $row['unit_value'], 2);
                if ($totalValue > 9999999999.99) {
                    throw ValidationException::withMessages(['items' => 'The distribution total exceeds the supported value.']);
                }
            }
            $record->items()->get()->each->forceDelete();
            foreach ($input['items'] as $row) {
                $record->items()->create(Arr::only($row, ['item_master_id', 'quantity', 'unit_value']));
            }
            $record->recalculateTotal();
            SGIPComplianceService::validate($record->refresh(), false);

            return $record->refresh();
        }, 3);
    }

    public function submitDistribution(SgipDistribution $record): void
    {
        abort_unless($record->isEditable(), 409, 'Only active unreserved drafts can be submitted.');
        if (! $record->items()->exists()) {
            throw ValidationException::withMessages(['items' => 'Add at least one item before submitting.']);
        }
        SGIPComplianceService::validate($record, true);
        $owner = $record->user;
        abort_unless($owner !== null && $owner->employee?->id === $record->employee_id, 422, 'The distribution employee must match its owner.');
        app(FieldSgipStockService::class)->assignSourceAndValidate($owner, $record);
        $record->approval_status = 'submitted';
        abort_unless($record->save(), 409, 'Could not submit the distribution.');
    }

    public function canAct(User $actor, SgipDistribution $record, string $action): bool
    {
        return $actor->can('view', $record) && $actor->can($this->ability($action), $record)
            && $this->allowsState($record, $action);
    }

    public function act(User $actor, array $ids, string $action, ?string $revision = null, bool $bulk = false): void
    {
        $this->authorizeRegister($actor);
        if ($bulk) {
            abort_unless(in_array($action, ['delete', 'restore', 'force-delete'], true)
                && $actor->can($this->ability($action).'Any', SgipDistribution::class), 403);
        }
        (new SgipDistribution)->getConnection()->transaction(function () use ($actor, $ids, $action, $revision): void {
            $records = $this->visible($actor)->withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $record) {
                abort_unless($actor->can('view', $record) && $actor->can($this->ability($action), $record), 403);
                abort_unless($this->allowsState($record, $action), 409, 'This action is no longer available for the distribution.');
                if ($revision !== null) {
                    $this->checkRevision($record, $revision);
                }
                if ($action === 'submit') {
                    $this->submitDistribution($record);
                } elseif ($action === 'approve') {
                    try {
                        app(InventoryService::class)->postSgipDistribution($record);
                    } catch (RuntimeException $exception) {
                        throw ValidationException::withMessages(['stock' => $exception->getMessage()]);
                    }
                } elseif ($action === 'return') {
                    abort_unless($record->update(['approval_status' => 'draft']), 409);
                } else {
                    abort_unless($record->{$this->ability($action)}(), 409, 'Could not update the distribution.');
                }
            }
        }, 3);
    }

    private function allowsState(SgipDistribution $record, string $action): bool
    {
        if ($record->isInventoryPosted()) {
            return false;
        }
        if ($record->trashed()) {
            return $record->approval_status === 'draft' && $record->inventory_reserved_at === null && in_array($action, ['restore', 'force-delete'], true);
        }

        return match ($action) {
            'submit', 'delete' => $record->isEditable(),
            'approve', 'return' => $record->approval_status === 'submitted',
            default => false,
        };
    }

    private function ability(string $action): string
    {
        return match ($action) {
            'submit', 'approve', 'return' => 'update', 'force-delete' => 'forceDelete', default => $action
        };
    }

    private function checkRevision(SgipDistribution $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The distribution changed. Reload it before continuing.');
    }
}
