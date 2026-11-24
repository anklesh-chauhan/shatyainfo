<?php

namespace App\Services\Inventory;

use App\Enums\SampleIssueStatus;
use App\Models\NumberSeries;
use App\Models\SampleIssue;
use App\Models\SampleIssueLine;
use App\Models\SampleRequest;
use App\Models\SampleRequestLine;
use App\Models\User;
use App\Services\OptionalModuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SampleIssueRegister
{
    public const RELATIONS = ['sampleRequest.employee', 'fromLocation', 'toLocation', 'issuedBy'];

    public function authorizeRegister(User $actor): void
    {
        abort_unless(app(OptionalModuleService::class)->navigationEnabled('SampleIssue')
            && $actor->can('viewAny', SampleIssue::class), 403);
    }

    public function visible(User $actor): Builder
    {
        return SampleIssue::query()->applyVisibility('SampleIssue', $actor);
    }

    public function find(User $actor, int $id, bool $lock = false): SampleIssue
    {
        $this->authorizeRegister($actor);
        $record = $this->visible($actor)->withTrashed()
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
        abort_unless($actor->can('view', $record), 403);

        return $record;
    }

    public function options(User $actor, string $type, string $search): array
    {
        $this->authorizeRegister($actor);
        abort_unless($actor->can('create', SampleIssue::class) || $actor->can('Update:SampleIssue'), 403);
        $requests = app(SampleRequestRegister::class);
        $column = $type === 'requests' ? 'document_number' : 'name';
        $query = $type === 'requests' ? $requests->issuable($actor) : $requests->catalog($actor, 'locations');
        $page = $query->when($search !== '', fn (Builder $query) => $query->whereLike($column, '%'.$search.'%'))
            ->orderBy($column)->orderBy('id')->paginate(30);

        return [
            'data' => $page->getCollection()->map(fn ($record): array => ['id' => $record->id, 'label' => $record->{$column}])->all(),
            'meta' => ['last_page' => $page->lastPage()],
        ];
    }

    public function request(User $actor, int $id): array
    {
        $this->authorizeRegister($actor);
        abort_unless($actor->can('create', SampleIssue::class) || $actor->can('Update:SampleIssue'), 403);
        $record = app(SampleRequestRegister::class)->issuable($actor)->with(['destinationLocation', 'lines.item'])->findOrFail($id);

        return [
            'id' => $record->id,
            'document_number' => $record->document_number,
            'to_location_id' => $record->location_master_id,
            'destination' => $record->destinationLocation?->name,
            'lines' => $record->lines->filter(fn (SampleRequestLine $line): bool => $line->remainingApprovedQuantity() > 0)
                ->map(fn (SampleRequestLine $line): array => [
                    'sample_request_line_id' => $line->id,
                    'item_master_id' => $line->item_master_id,
                    'item' => $line->item?->item_name,
                    'quantity' => $line->remainingApprovedQuantity(),
                    'unit_cost' => $line->item?->purchase_price,
                ])->values()->all(),
        ];
    }

    public function summary(User $actor, SampleIssue $record): array
    {
        return [
            ...$record->only(['id', 'document_number', 'sample_request_id', 'from_location_id', 'to_location_id']),
            'issue_date' => $record->issue_date->toDateString(),
            'status' => $record->status->value,
            'status_label' => $record->status->getLabel(),
            'request_number' => $record->sampleRequest?->document_number,
            'representative' => $record->sampleRequest?->employee?->full_name,
            'source' => $record->fromLocation?->name,
            'destination' => $record->toLocation?->name,
            'trashed' => $record->trashed(),
            'can_view' => $actor->can('view', $record),
            'trash_actions' => collect(['delete', 'restore', 'force-delete'])
                ->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all(),
        ];
    }

    public function detail(User $actor, SampleIssue $record): array
    {
        $record->loadMissing([...self::RELATIONS, 'lines.item']);

        return [
            ...$this->summary($actor, $record),
            'notes' => $record->notes,
            'posted_at' => $record->posted_at?->toIso8601String(),
            'issued_by' => $record->issued_by,
            'issuer' => $record->issuedBy?->name,
            'revision' => $this->revision($record),
            'can_edit' => $record->isEditable() && $actor->can('update', $record),
            'actions' => collect(['post', 'delete', 'restore', 'force-delete'])
                ->filter(fn (string $action): bool => $this->canAct($actor, $record, $action))->values()->all(),
            'lines' => $record->lines->map(fn (SampleIssueLine $line): array => [
                ...$line->only(['id', 'sample_request_line_id', 'item_master_id', 'quantity', 'unit_cost', 'remarks']),
                'item' => $line->item?->item_name,
            ])->values()->all(),
        ];
    }

    public function revision(SampleIssue $record): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive([
            $record->getAttributes(),
            $record->loadMissing('lines')->lines->keyBy('id')->map->getAttributes()->all(),
        ]), JSON_THROW_ON_ERROR));
    }

    public function save(User $actor, ?int $id, array $input): SampleIssue
    {
        $this->authorizeRegister($actor);

        return (new SampleIssue)->getConnection()->transaction(function () use ($actor, $id, $input): SampleIssue {
            $record = $id === null ? new SampleIssue : $this->find($actor, $id, lock: true);
            if ($id === null) {
                abort_unless($actor->can('create', SampleIssue::class), 403);
                NumberSeries::getNextNumber(SampleIssue::class);
                NumberSeries::query()->where('model_type', SampleIssue::class)->whereNull('type_master_id')->lockForUpdate()->firstOrFail();
            } else {
                abort_unless($actor->can('update', $record), 403);
                abort_unless($record->isEditable(), 409, 'Only active draft issues can be edited.');
                $this->checkRevision($record, $input['revision']);
            }

            $request = $this->approvedRequest($actor, (int) $input['sample_request_id']);
            $this->validateLocations($actor, $request, (int) $input['from_location_id']);
            $requestLines = $request->lines()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = $record->loadMissing('lines')->lines->keyBy('id');
            $selectedIds = array_filter(array_column($input['lines'], 'id'));
            if (count($selectedIds) !== count(array_unique($selectedIds))) {
                throw ValidationException::withMessages(['lines' => 'Each existing issue line can only be saved once.']);
            }
            $requested = [];
            foreach ($input['lines'] as $index => $row) {
                if (isset($row['id']) && ! $lines->has($row['id'])) {
                    throw ValidationException::withMessages(["lines.{$index}.id" => 'Choose a line from this issue.']);
                }
                $requestLine = $requestLines->get($row['sample_request_line_id']);
                $requested[$row['sample_request_line_id']] = ($requested[$row['sample_request_line_id']] ?? 0) + (float) $row['quantity'];
                if ($requestLine === null || (int) $row['item_master_id'] !== $requestLine->item_master_id
                    || round($requested[$requestLine->id], 3) > $requestLine->remainingApprovedQuantity()) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Choose an approved request line and a quantity within its remaining approval.']);
                }
            }

            $record->fill(Arr::only($input, ['sample_request_id', 'from_location_id', 'issue_date', 'notes'])
                + ['notes' => null]);
            $record->to_location_id = $request->location_master_id;
            abort_unless($record->save(), 409, 'Could not save the sample issue.');
            foreach ($input['lines'] as $row) {
                $line = $lines->pull($row['id'] ?? 0) ?? $record->lines()->make();
                $line->fill(Arr::only($row, ['sample_request_line_id', 'item_master_id', 'quantity', 'unit_cost', 'remarks'])
                    + ['unit_cost' => null, 'remarks' => null]);
                abort_unless($line->save(), 409, 'Could not save an issue line.');
            }
            foreach ($lines as $removed) {
                abort_unless($removed->delete(), 409, 'Could not remove an issue line.');
            }

            return $record->refresh();
        }, 3);
    }

    public function canAct(User $actor, SampleIssue $record, string $action): bool
    {
        return $actor->can('view', $record) && $actor->can($this->ability($action), $record)
            && $this->allowsState($record, $action);
    }

    public function act(User $actor, array $ids, string $action, ?string $revision = null, bool $bulk = false): void
    {
        $this->authorizeRegister($actor);
        if ($bulk) {
            abort_unless($action !== 'post' && $actor->can($this->ability($action).'Any', SampleIssue::class), 403);
        }
        (new SampleIssue)->getConnection()->transaction(function () use ($actor, $ids, $action, $revision): void {
            $records = $this->visible($actor)->withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $record) {
                abort_unless($actor->can('view', $record) && $actor->can($this->ability($action), $record), 403);
                abort_unless($this->allowsState($record, $action), 409, 'This action is no longer available for the issue.');
                if ($revision !== null) {
                    $this->checkRevision($record, $revision);
                }
                if ($action === 'post') {
                    $request = $this->approvedRequest($actor, $record->sample_request_id);
                    $this->validateLocations($actor, $request, $record->from_location_id);
                    try {
                        app(InventoryService::class)->postSampleIssue($record, $actor->id);
                    } catch (RuntimeException $exception) {
                        throw ValidationException::withMessages(['stock' => $exception->getMessage()]);
                    }
                } else {
                    abort_unless($record->{$this->ability($action)}(), 409, 'Could not update the sample issue.');
                }
            }
        }, 3);
    }

    private function approvedRequest(User $actor, int $id): SampleRequest
    {
        return app(SampleRequestRegister::class)->issuable($actor)->lockForUpdate()->findOrFail($id);
    }

    private function validateLocations(User $actor, SampleRequest $request, int $source): void
    {
        $locations = app(SampleRequestRegister::class)->catalog($actor, 'locations');
        if ($source === $request->location_master_id || (clone $locations)->whereKey([$source, $request->location_master_id])->count() !== 2) {
            throw ValidationException::withMessages(['from_location_id' => 'Choose distinct available source and destination locations.']);
        }
    }

    private function ability(string $action): string
    {
        return match ($action) {
            'post' => 'update',
            'force-delete' => 'forceDelete',
            default => $action,
        };
    }

    private function allowsState(SampleIssue $record, string $action): bool
    {
        if ($record->status !== SampleIssueStatus::Draft || $record->isPosted()) {
            return false;
        }

        return $record->trashed() ? in_array($action, ['restore', 'force-delete'], true) : in_array($action, ['post', 'delete'], true);
    }

    private function checkRevision(SampleIssue $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The issue changed. Reload it before continuing.');
    }
}
