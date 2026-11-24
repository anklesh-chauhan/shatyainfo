<?php

namespace App\Services\Inventory;

use App\Enums\MarketingCampaignStatus;
use App\Enums\SampleRequestStatus;
use App\Filament\Resources\SampleIssues\SampleIssueResource;
use App\Models\Employee;
use App\Models\ItemMaster;
use App\Models\LocationMaster;
use App\Models\MarketingCampaign;
use App\Models\NumberSeries;
use App\Models\SampleRequest;
use App\Models\SampleRequestLine;
use App\Models\Territory;
use App\Models\User;
use App\Services\OptionalModuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class SampleRequestRegister
{
    private const SELECTIONS = [
        'employee_id' => 'employees',
        'location_master_id' => 'locations',
        'territory_id' => 'territories',
        'campaign_id' => 'campaigns',
    ];

    private const CATALOGS = [
        'employees' => [Employee::class, 'first_name'],
        'locations' => [LocationMaster::class, 'name'],
        'territories' => [Territory::class, 'name'],
        'campaigns' => [MarketingCampaign::class, 'name'],
        'items' => [ItemMaster::class, 'item_name'],
    ];

    public const RELATIONS = ['employee', 'territory', 'destinationLocation', 'campaign'];

    public function authorizeRegister(User $actor): void
    {
        abort_unless(app(OptionalModuleService::class)->navigationEnabled('SampleRequest')
            && $actor->can('viewAny', SampleRequest::class), 403);
    }

    public function visible(User $actor): Builder
    {
        return SampleRequest::query()->applyVisibility('SampleRequest', $actor);
    }

    public function find(User $actor, int $id, bool $lock = false): SampleRequest
    {
        $this->authorizeRegister($actor);
        $record = $this->visible($actor)->withTrashed()->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
        abort_unless($actor->can('view', $record), 403);

        return $record;
    }

    public function catalog(User $actor, string $type): Builder
    {
        [$model] = self::CATALOGS[$type];
        $query = $model::query()->applyVisibility(class_basename($model), $actor);

        return match ($type) {
            'employees', 'locations' => $query->where('is_active', true),
            'campaigns' => $query->where('status', MarketingCampaignStatus::Active)
                ->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today()),
            'items' => $query->whereNotNull('item_type')->whereNull('parent_id'),
            default => $query,
        };
    }

    public function options(User $actor, string $type, string $search): array
    {
        $this->authorizeRegister($actor);
        abort_unless($actor->can('create', SampleRequest::class) || $actor->can('Update:SampleRequest'), 403);
        [, $name] = self::CATALOGS[$type];
        $page = $this->catalog($actor, $type)
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($name, $type, $search): void {
                $match->whereLike($name, '%'.$search.'%');
                if ($type === 'employees') {
                    $match->orWhereLike('last_name', '%'.$search.'%');
                }
            }))
            ->orderBy($name)->orderBy('id')->paginate(30);

        return [
            'data' => $page->getCollection()->map(fn ($record): array => [
                'id' => $record->id,
                'label' => $type === 'employees' ? $record->full_name : $record->{$name},
            ])->all(),
            'meta' => ['last_page' => $page->lastPage()],
        ];
    }

    public function summary(User $actor, SampleRequest $record): array
    {
        return [
            ...$record->only(['id', 'document_number', 'employee_id', 'territory_id', 'location_master_id', 'campaign_id']),
            'request_date' => $record->request_date->toDateString(),
            'status' => $record->status->value,
            'status_label' => $record->status->getLabel(),
            'representative' => $record->employee?->full_name,
            'territory' => $record->territory?->name,
            'destination' => $record->destinationLocation?->name,
            'campaign' => $record->campaign?->name,
            'trashed' => $record->trashed(),
            'can_view' => $actor->can('view', $record),
            'trash_actions' => collect(['delete', 'restore', 'force-delete'])
                ->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all(),
        ];
    }

    public function detail(User $actor, SampleRequest $record): array
    {
        $record->loadMissing([...self::RELATIONS, 'lines.item']);
        $canUpdate = $actor->can('update', $record);
        $actions = collect(['submit', 'approve', 'cancel', 'delete', 'restore', 'force-delete'])
            ->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all();

        return [
            ...$this->summary($actor, $record),
            'purpose' => $record->purpose,
            'revision' => $this->revision($record),
            'can_edit' => $canUpdate && $record->isEditable(),
            'actions' => $actions,
            'issue_request_id' => $this->canHandoff($actor, $record) ? $record->id : null,
            'issue_url' => $this->canHandoff($actor, $record)
                ? SampleIssueResource::getUrl('create', ['sample_request_id' => $record->id], panel: 'admin')
                : null,
            'lines' => $record->lines->map(fn (SampleRequestLine $line): array => [
                ...$line->only(['id', 'item_master_id', 'quantity_requested', 'quantity_approved', 'quantity_issued', 'remarks']),
                'item' => $line->item?->item_name,
            ])->values()->all(),
        ];
    }

    public function revision(SampleRequest $record): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive([
            $record->getAttributes(),
            $record->loadMissing('lines')->lines->keyBy('id')->map->getAttributes()->all(),
        ]), JSON_THROW_ON_ERROR));
    }

    public function save(User $actor, ?int $id, array $input): SampleRequest
    {
        $this->authorizeRegister($actor);

        return (new SampleRequest)->getConnection()->transaction(function () use ($actor, $id, $input): SampleRequest {
            $record = $id === null ? new SampleRequest : $this->find($actor, $id, lock: true);
            if ($id === null) {
                abort_unless($actor->can('create', SampleRequest::class), 403);
                NumberSeries::getNextNumber(SampleRequest::class);
                NumberSeries::query()->where('model_type', SampleRequest::class)
                    ->whereNull('type_master_id')->lockForUpdate()->firstOrFail();
            } else {
                abort_unless($actor->can('update', $record), 403);
                abort_unless($record->isEditable(), 409, 'Only active draft requests can be edited.');
                $this->checkRevision($record, $input['revision']);
            }

            foreach (self::SELECTIONS as $field => $type) {
                $value = $input[$field] ?? null;
                if ($value !== null && (int) $value !== $record->{$field}) {
                    $this->validateSelection($actor, $type, $value, $field);
                }
            }

            $lines = $record->loadMissing('lines')->lines->keyBy('id');
            $selectedIds = array_filter(array_column($input['lines'], 'id'));
            if (count($selectedIds) !== count(array_unique($selectedIds))) {
                throw ValidationException::withMessages(['lines' => 'Each existing request line can only be saved once.']);
            }
            foreach ($input['lines'] as $index => $row) {
                $line = $lines->get($row['id'] ?? 0);
                if (isset($row['id']) && $line === null) {
                    throw ValidationException::withMessages(["lines.{$index}.id" => 'Choose a line from this request.']);
                }
                if ((int) $row['item_master_id'] !== $line?->item_master_id) {
                    $this->validateSelection($actor, 'items', $row['item_master_id'], "lines.{$index}.item_master_id");
                }
            }

            $record->fill(Arr::only($input, [...array_keys(self::SELECTIONS), 'request_date', 'purpose'])
                + ['territory_id' => null, 'campaign_id' => null]);
            abort_unless($record->save(), 409, 'Could not save the sample request.');
            foreach ($input['lines'] as $row) {
                $line = $lines->pull($row['id'] ?? 0) ?? $record->lines()->make();
                $line->fill(Arr::only($row, ['item_master_id', 'quantity_requested', 'remarks'])
                    + ['remarks' => null, 'quantity_approved' => 0, 'quantity_issued' => 0]);
                abort_unless($line->save(), 409, 'Could not save a request line.');
            }
            foreach ($lines as $removed) {
                abort_unless($removed->delete(), 409, 'Could not remove a request line.');
            }

            return $record->refresh();
        }, 3);
    }

    public function canHandoff(User $actor, SampleRequest $record): bool
    {
        return ! $record->trashed()
            && in_array($record->status, [SampleRequestStatus::Approved, SampleRequestStatus::PartiallyIssued], true)
            && $actor->can('viewAny', SampleRequest::class)
            && $actor->can('view', $record)
            && app(OptionalModuleService::class)->navigationEnabled('SampleIssue')
            && $actor->can(['ViewAny:SampleIssue', 'Create:SampleIssue'])
            && $record->lines->contains(fn (SampleRequestLine $line): bool => $line->remainingApprovedQuantity() > 0);
    }

    public function issuable(User $actor): Builder
    {
        $query = $this->visible($actor)->whereIn('status', [SampleRequestStatus::Approved, SampleRequestStatus::PartiallyIssued])
            ->whereHas('lines', fn (Builder $lines) => $lines->whereColumn('quantity_approved', '>', 'quantity_issued'));
        if (! app(OptionalModuleService::class)->navigationEnabled('SampleRequest')
            || ! $actor->can(['ViewAny:SampleRequest', 'View:SampleRequest'])) {
            $query->whereKey([]);
        }

        return $query;
    }

    public function canAct(User $actor, SampleRequest $record, string $action): bool
    {
        return $actor->can('view', $record)
            && $actor->can($this->ability($action), $record)
            && $this->allowsState($record, $action)
            && (! in_array($action, ['delete', 'force-delete'], true)
                || ! ($record->has_issues ?? $record->issues()->withTrashed()->exists()));
    }

    public function act(User $actor, array $ids, string $action, ?string $revision = null, array $approved = [], bool $bulk = false): void
    {
        $this->authorizeRegister($actor);
        if ($bulk) {
            abort_unless($actor->can($this->ability($action).'Any', SampleRequest::class), 403);
        }

        (new SampleRequest)->getConnection()->transaction(function () use ($actor, $ids, $action, $revision, $approved): void {
            $records = $this->visible($actor)->withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);

            foreach ($records as $record) {
                abort_unless($actor->can('view', $record) && $actor->can($this->ability($action), $record), 403);
                abort_unless($this->allowsState($record, $action), 409, 'This action is no longer available for the request.');
                if ($revision !== null) {
                    $this->checkRevision($record, $revision);
                }
                if (in_array($action, ['delete', 'force-delete'], true)) {
                    abort_if($record->issues()->withTrashed()->exists(), 409, 'Requests linked to issues must be retained.');
                }
                if ($action === 'submit' && ! $record->lines()->exists()) {
                    throw ValidationException::withMessages(['lines' => 'Add at least one requested item.']);
                }
                if ($action === 'approve') {
                    $this->approveLines($record, $approved);
                }

                $changed = match ($action) {
                    'submit' => $record->update(['status' => SampleRequestStatus::Submitted]),
                    'approve' => $record->update(['status' => SampleRequestStatus::Approved]),
                    'cancel' => $record->update(['status' => SampleRequestStatus::Cancelled]),
                    default => $record->{$this->ability($action)}(),
                };
                abort_unless($changed, 409, 'Could not update the sample request.');
            }
        }, 3);
    }

    private function approveLines(SampleRequest $record, array $approved): void
    {
        $lines = $record->lines()->orderBy('id')->lockForUpdate()->get();
        if ($lines->isEmpty() || $lines->count() !== count($approved)) {
            throw ValidationException::withMessages(['approved' => 'Enter an approved quantity for every request line.']);
        }
        foreach ($lines as $line) {
            $quantity = $approved[$line->id] ?? null;
            if (! is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity > (float) $line->quantity_requested
                || ! preg_match('/^\d+(?:\.\d{1,3})?$/D', (string) $quantity)) {
                throw ValidationException::withMessages(['approved' => 'Enter a positive approved quantity up to the requested amount for each line, with up to three decimal places.']);
            }
            abort_unless($line->update(['quantity_approved' => $quantity]), 409, 'Could not approve a request line.');
        }
    }

    private function ability(string $action): string
    {
        return match ($action) {
            'delete', 'restore' => $action,
            'force-delete' => 'forceDelete',
            default => 'update',
        };
    }

    private function allowsState(SampleRequest $record, string $action): bool
    {
        if ($record->trashed()) {
            return $action === 'restore' || ($action === 'force-delete' && $record->status === SampleRequestStatus::Draft);
        }

        return match ($action) {
            'submit', 'delete' => $record->status === SampleRequestStatus::Draft,
            'approve' => $record->status === SampleRequestStatus::Submitted,
            'cancel' => in_array($record->status, [SampleRequestStatus::Draft, SampleRequestStatus::Submitted, SampleRequestStatus::Approved], true),
            default => false,
        };
    }

    private function checkRevision(SampleRequest $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The request changed. Reload it before continuing.');
    }

    private function validateSelection(User $actor, string $type, int $id, string $field): void
    {
        if (! $this->catalog($actor, $type)->whereKey($id)->exists()) {
            throw ValidationException::withMessages([$field => 'Select an available option.']);
        }
    }
}
