<?php

namespace App\Services\Field;

use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Models\User;
use App\Models\VisitPreference;
use App\Services\OptionalModuleService;
use Illuminate\Database\Eloquent\Builder;

class DcrRegisterService
{
    public function index(User $actor, array $filters): array
    {
        $team = ($filters['scope'] ?? null) === 'team';
        $query = $this->query($actor, $team);
        match ($filters['trash'] ?? 'active') {
            'only' => $query->onlyTrashed(),
            'with' => $query->withTrashed(),
            default => $query,
        };
        $users = $team ? User::query()->whereIn('id', (clone $query)->select('user_id'))
            ->orderBy('name')->orderBy('id')->get(['id', 'name']) : [];
        $preferences = VisitPreference::current();
        $page = $this->loadSummaries($query)
            ->when($filters['user_id'] ?? null, fn (Builder $rows, int $id): Builder => $rows->where('user_id', $id))
            ->when($filters['approval_status'] ?? null, fn (Builder $rows, string $status): Builder => $rows->where('approval_status', $status))
            ->when($filters['from'] ?? null, fn (Builder $rows, string $date): Builder => $rows->where('dcr_date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $rows, string $date): Builder => $rows->where('dcr_date', '<=', $date))
            ->orderByDesc('dcr_date')->orderByDesc('id')->paginate(25)
            ->through(fn (SalesDcr $report): array => $this->summary($actor, $report, $preferences));

        return ['data' => $page->items(), 'meta' => [
            'last_page' => $page->lastPage(),
            'users' => $users,
            'can_view_team' => $this->query($actor, true)->withTrashed()->exists(),
            'can_open_today' => $actor->can('create', SalesDcr::class)
                || $this->query($actor, false)->whereDate('dcr_date', today())->exists(),
            'can_bulk_restore' => $actor->can('restoreAny', SalesDcr::class),
            'can_bulk_force_delete' => $actor->can('forceDeleteAny', SalesDcr::class),
        ]];
    }

    public function readableScope(User $actor, int $id): ?string
    {
        if (! $actor->can(['ViewAny:SalesDcr', 'View:SalesDcr'])) {
            return null;
        }
        if ($this->query($actor, false)->whereKey($id)->exists()) {
            return 'personal';
        }

        return $this->query($actor, true)->whereKey($id)->exists() ? 'team' : null;
    }

    public function detail(User $actor, int $id, bool $team): array
    {
        $report = $this->loadSummaries($this->query($actor, $team)->withTrashed())->findOrFail($id);
        $details = [
            ...$this->summary($actor, $report, VisitPreference::current()),
            'remarks' => $report->remarks,
            'field_entries' => $report->fieldEntries()->orderBy('captured_at')->get(['category', 'remarks']),
        ];

        if ($actor->can(['ViewAny:Visit', 'View:Visit'])) {
            $details['visits'] = $report->visits()
                ->when($team, fn (Builder $rows): Builder => $rows->applyVisibility('Visit', $actor),
                    fn (Builder $rows): Builder => $rows->where('employee_id', $actor->id))
                ->orderBy('id')->get(['id', 'document_number', 'visit_status']);
        }
        if (app(OptionalModuleService::class)->navigationEnabled('SalesDcrExpense')
            && $actor->can(['ViewAny:SalesDcrExpense', 'View:SalesDcrExpense'])) {
            $details['expenses'] = $report->expenses()
                ->when($team, fn (Builder $rows): Builder => $rows->applyVisibility('SalesDcrExpense', $actor))
                ->with('expenseType:id,name')->orderBy('id')->get()->map(fn (SalesDcrExpense $expense): array => [
                    'label' => $expense->expenseType?->name,
                    'amount' => (float) $expense->amount,
                    'is_auto_calculated' => (bool) $expense->is_auto_calculated,
                ]);
        }

        return $details;
    }

    public function apply(User $actor, array $ids, bool $team, string $action): int
    {
        if (count($ids) > 1 && $action !== 'delete') {
            abort_unless($actor->can($action.'Any', SalesDcr::class), 403);
        }

        return $actor->getConnection()->transaction(function () use ($actor, $ids, $team, $action): int {
            $query = $this->query($actor, $team)->withTrashed()->whereKey($ids)->orderBy('id');
            $owners = (clone $query)->pluck('user_id', 'id');
            abort_unless($owners->count() === count($ids), 404);
            User::query()->whereKey($owners->unique()->values()->all())->orderBy('id')->lockForUpdate()->get(['id']);
            $locked = (clone $query)->lockForUpdate()->pluck('user_id', 'id');
            abort_unless($locked->count() === count($ids), 404);
            abort_unless($owners->all() === $locked->all(), 409, 'The report changed. Refresh the register.');

            $reports = $this->loadSummaries($query)->get();
            $preferences = VisitPreference::current();
            $restoring = [];
            foreach ($reports as $report) {
                abort_unless($actor->can($action, $report), 403);
                abort_unless($report->trashed() === ($action !== 'delete'), 409, 'The report changed. Refresh the register.');
                abort_unless($this->eligibleDraft($report, $preferences), 422, 'Only empty drafts in the reporting window can be changed here.');
                app(FieldDcrService::class)->assertEditable($report);
                app(FieldSyncRecoveryService::class)->assertNoPendingReview($report);
                abort_if(app(FieldDeviceService::class)->blockingDevices($report)->isNotEmpty(), 422, 'Synchronize pending work for this date first.');

                if ($action === 'restore') {
                    $date = $report->dcr_date->toDateString();
                    $key = $report->user_id.'|'.$date;
                    abort_if(isset($restoring[$key]) || SalesDcr::query()->where('user_id', $report->user_id)->whereDate('dcr_date', $date)->exists(),
                        409, 'An active report already exists for this representative and date.');
                    $restoring[$key] = true;
                }
            }

            foreach ($reports as $report) {
                $changed = match ($action) {
                    'delete' => $report->delete(),
                    'restore' => $report->restore(),
                    'forceDelete' => $report->forceDelete(),
                };
                abort_unless($changed, 409, 'The report could not be updated.');
            }

            return $reports->count();
        }, attempts: 3);
    }

    private function query(User $actor, bool $team): Builder
    {
        abort_unless($actor->can(['ViewAny:SalesDcr', 'View:SalesDcr']), 403);

        return $team
            ? SalesDcr::query()->applyVisibility('SalesDcr', $actor)->where('user_id', '!=', $actor->id)
            : SalesDcr::query()->where('user_id', $actor->id);
    }

    private function loadSummaries(Builder $query): Builder
    {
        return $query->with(['user:id,name', 'territory:id,name', 'approval'])->withCount('visits as registered_visits_count')->withExists([
            'visits as has_visit_history' => fn (Builder $rows): Builder => $rows->withTrashed(),
            'expenses' => fn (Builder $rows): Builder => $rows->withTrashed(),
            'travelSegments' => fn (Builder $rows): Builder => $rows->withTrashed(),
            'fieldEntries', 'fieldVisitEvents', 'rcpaObservations',
        ]);
    }

    private function eligibleDraft(SalesDcr $report, VisitPreference $preferences): bool
    {
        return $report->approval_status === 'draft'
            && $report->submitted_at === null && $report->approved_at === null && $report->approval === null
            && $report->sales_tour_plan_id === null
            && ! $report->has_visit_history && ! $report->expenses_exists && ! $report->travel_segments_exists
            && ! $report->field_entries_exists && ! $report->field_visit_events_exists && ! $report->rcpa_observations_exists
            && blank($report->remarks) && blank($report->route)
            && (float) $report->total_expense === 0.0 && (float) $report->distance_covered === 0.0
            && (float) $report->duration === 0.0 && (float) $report->orders_count === 0.0
            && $report->dcr_date !== null && $preferences->allowsDcrDate($report->dcr_date);
    }

    private function summary(User $actor, SalesDcr $report, VisitPreference $preferences): array
    {
        $actions = $report->trashed() ? ['restore', 'forceDelete'] : ['delete'];

        return [
            'id' => $report->id,
            'dcr_date' => $report->dcr_date?->toDateString(),
            'representative' => $report->user?->name,
            'territory' => $report->territory?->name,
            'approval_status' => $report->approval_status,
            'visits_count' => $report->registered_visits_count,
            'total_expense' => (float) $report->total_expense,
            'trashed' => $report->trashed(),
            'can_open_workspace' => ! $report->trashed() && (int) $report->user_id === (int) $actor->id,
            'actions' => $this->eligibleDraft($report, $preferences)
                ? array_values(array_filter($actions, fn (string $action): bool => $actor->can($action, $report))) : [],
        ];
    }
}
