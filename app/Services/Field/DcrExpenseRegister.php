<?php

namespace App\Services\Field;

use App\Models\ExpenseType;
use App\Models\Media;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Models\TransportMode;
use App\Models\User;
use App\Models\VisitPreference;
use App\Services\Expense\ExpenseCalculationService;
use App\Services\OptionalModuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class DcrExpenseRegister
{
    public function __construct(private FieldDcrService $dcrs, private OptionalModuleService $modules) {}

    private const FIELDS = ['sales_dcr_id', 'expense_type_id', 'transport_mode_id', 'amount', 'quantity', 'rate', 'remarks'];

    public function index(User $actor, array $filters): array
    {
        $team = ($filters['scope'] ?? null) === 'team';
        $query = $this->query($actor, $team);
        match ($filters['trash'] ?? 'active') {
            'only' => $query->onlyTrashed(),
            'with' => $query->withTrashed(),
            default => $query,
        };
        $preferences = VisitPreference::current();
        $page = $this->withDetails($query)
            ->when($filters['sales_dcr_id'] ?? null, fn (Builder $rows, int $id): Builder => $rows->where('sales_dcr_id', $id))
            ->when($filters['origin'] ?? null, fn (Builder $rows, string $origin): Builder => $rows->where('is_auto_calculated', $origin === 'calculated'))
            ->when($filters['search'] ?? null, fn (Builder $rows, string $term): Builder => $rows->where(function (Builder $search) use ($term): void {
                $search->whereHas('expenseType', fn (Builder $types): Builder => $types->whereLike('name', '%'.$term.'%'))
                    ->orWhereHas('salesDcr.user', fn (Builder $users): Builder => $users->whereLike('name', '%'.$term.'%'));
                if (ctype_digit($term) && filter_var($term, FILTER_VALIDATE_INT) !== false) {
                    $search->orWhere('sales_dcr_id', (int) $term);
                }
            }))
            ->latest('id')->paginate(25)->through(fn (SalesDcrExpense $row): array => $this->summary($actor, $row, $preferences));

        return ['data' => $page->items(), 'meta' => [
            'last_page' => $page->lastPage(),
            'can_view_team' => $this->reports($actor, true)->exists(),
            'can_create' => $actor->can('create', SalesDcrExpense::class),
            'can_calculate' => $actor->can('Update:SalesDcrExpense'),
            'can_bulk_restore' => $actor->can('restoreAny', SalesDcrExpense::class),
            'can_bulk_force_delete' => $actor->can('forceDeleteAny', SalesDcrExpense::class),
        ]];
    }

    public function detail(User $actor, int $id, bool $team): array
    {
        $row = $this->withDetails($this->query($actor, $team)->withTrashed())->findOrFail($id);
        $data = $this->summary($actor, $row, VisitPreference::current());
        if (! $team && ! $row->trashed()) {
            $data['receipts'] = $row->receipts()->where('disk', 'local')->orderBy('id')->get(['id', 'original_name'])
                ->map(fn (Media $receipt): array => ['id' => $receipt->id, 'name' => $receipt->original_name]);
        }

        return $data;
    }

    public function options(User $actor, array $filters): array
    {
        $this->authorizeView($actor);
        abort_unless($actor->can('Create:SalesDcrExpense') || $actor->can('Update:SalesDcrExpense'), 403);
        $kind = $filters['kind'];
        $term = $filters['search'] ?? '';
        $pattern = '%'.$term.'%';
        $window = VisitPreference::current()->dcrReportingWindow();
        $query = match ($kind) {
            'reports' => $this->reports($actor, ($filters['scope'] ?? null) === 'team')
                ->whereIn('approval_status', ['draft', 'rejected'])
                ->whereBetween('dcr_date', [$window['earliest_date'], $window['latest_date']])
                ->whereDoesntHave('approval', fn (Builder $rows): Builder => $rows->whereIn('approval_status', ['pending', 'approved']))
                ->with('user:id,name')
                ->when($term !== '', fn (Builder $reports): Builder => $reports->where(function (Builder $matches) use ($term, $pattern): void {
                    $matches->whereHas('user', fn (Builder $users): Builder => $users->whereLike('name', $pattern));
                    if (ctype_digit($term) && filter_var($term, FILTER_VALIDATE_INT) !== false) {
                        $matches->orWhere('id', (int) $term);
                    }
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $term, $date) && checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
                        $matches->orWhereDate('dcr_date', $term);
                    }
                }))->latest('dcr_date')->latest('id'),
            'types' => ExpenseType::query()->where('is_active', true)->whereLike('name', $pattern)->orderBy('name')->orderBy('id'),
            'modes' => TransportMode::query()->whereLike('name', $pattern)->orderBy('name')->orderBy('id'),
        };
        $page = $query->paginate(25)->through(fn (Model $row): array => [
            'id' => $row->id, 'label' => $row instanceof SalesDcr ? $this->reportLabel($row) : $row->name,
            ...($row instanceof SalesDcr ? ['date' => $row->dcr_date?->toDateString()] : []),
        ]);

        return ['data' => $page->items(), 'meta' => ['last_page' => $page->lastPage()]];
    }

    public function save(User $actor, array $input, ?int $id = null): array
    {
        $this->authorizeView($actor);
        if ($id === null) {
            abort_unless($actor->can('create', SalesDcrExpense::class), 403);
        }
        $team = $input['scope'] === 'team';
        $target = (int) $input['sales_dcr_id'];

        return $actor->getConnection()->transaction(function () use ($actor, $input, $id, $team, $target): array {
            $previous = $id === null ? null : $this->query($actor, $team)->findOrFail($id, ['id', 'sales_dcr_id']);
            $parents = $this->lockReports($actor, $team, array_filter([$target, $previous?->sales_dcr_id]));
            $destination = $parents->get($target);
            $this->dcrs->assertEditable($destination);
            $expense = new SalesDcrExpense(['is_auto_calculated' => false]);
            $source = null;
            if ($previous) {
                $expense = $this->query($actor, $team)->lockForUpdate()->findOrFail($id);
                abort_unless($actor->can('update', $expense), 403);
                abort_unless($expense->sales_dcr_id === $previous->sales_dcr_id
                    && hash_equals($this->revision($expense), $input['revision']), 409, 'This expense changed. Reload it before saving.');
                $this->assertManual($expense);
                $source = $parents->get($expense->sales_dcr_id);
                $this->dcrs->assertEditable($source);
                if ((int) $source->id === $target) {
                    $source = null;
                } else {
                    $this->assertNoEvidence($expense);
                    $this->assertNoPendingWork($source);
                    $this->assertNoPendingWork($destination);
                }
            }
            if ((int) $expense->expense_type_id !== (int) $input['expense_type_id']
                && ! ExpenseType::query()->where('is_active', true)->whereKey($input['expense_type_id'])->exists()) {
                throw ValidationException::withMessages(['expense_type_id' => 'Choose an active expense type.']);
            }
            if (isset($input['transport_mode_id']) && (int) $expense->transport_mode_id !== (int) $input['transport_mode_id']
                && ! TransportMode::query()->whereKey($input['transport_mode_id'])->exists()) {
                throw ValidationException::withMessages(['transport_mode_id' => 'Choose an available transport mode.']);
            }
            $expense->fill(array_intersect_key($input, array_flip(self::FIELDS)))->save();
            $source?->recalculateTotalExpense();

            return $this->detail($actor, $expense->id, $team);
        }, attempts: 3);
    }

    public function lifecycle(User $actor, bool $team, string $action, array $ids): int
    {
        if (count($ids) > 1 && $action !== 'delete') {
            abort_unless($actor->can($action.'Any', SalesDcrExpense::class), 403);
        }

        return $actor->getConnection()->transaction(function () use ($actor, $team, $action, $ids): int {
            $query = $this->query($actor, $team)->withTrashed()->whereKey($ids)->orderBy('id');
            $parentIds = (clone $query)->pluck('sales_dcr_id', 'id');
            abort_unless($parentIds->count() === count($ids), 404);
            $parents = $this->lockReports($actor, $team, $parentIds->values()->all());
            $expenses = $query->lockForUpdate()->get();
            abort_unless($expenses->count() === count($ids), 404);
            foreach ($expenses as $expense) {
                abort_unless($parentIds->get($expense->id) === $expense->sales_dcr_id, 409, 'The expense changed. Refresh the register.');
                abort_unless($actor->can($action, $expense), 403);
                abort_unless($expense->trashed() === ($action !== 'delete'), 409, 'The expense changed. Refresh the register.');
                $this->assertManual($expense);
                if ($action === 'forceDelete') {
                    $this->assertNoEvidence($expense);
                }
            }
            foreach ($parents as $report) {
                $this->dcrs->assertEditable($report);
                $this->assertNoPendingWork($report);
            }
            foreach ($expenses as $expense) {
                $changed = match ($action) {
                    'delete' => $expense->delete(),
                    'restore' => $expense->restore(),
                    'forceDelete' => $expense->forceDelete(),
                };
                abort_unless($changed, 409, 'The expense could not be updated.');
            }

            return $expenses->count();
        }, attempts: 3);
    }

    public function recalculate(User $actor, int $id, bool $team, ExpenseCalculationService $calculation): void
    {
        $this->authorizeView($actor);
        abort_unless($actor->can('Update:SalesDcrExpense'), 403);
        $actor->getConnection()->transaction(function () use ($actor, $id, $team, $calculation): void {
            $report = $this->reports($actor, $team)->lockForUpdate()->findOrFail($id);
            $this->dcrs->assertEditable($report);
            $this->assertNoPendingWork($report);
            $calculation->autoCalculateDcrExpenses($report);
        }, attempts: 3);
    }

    private function authorizeView(User $actor): void
    {
        abort_unless($this->modules->navigationEnabled('SalesDcrExpense')
            && $actor->can(['ViewAny:SalesDcr', 'View:SalesDcr', 'ViewAny:SalesDcrExpense', 'View:SalesDcrExpense']), 403);
    }

    private function reports(User $actor, bool $team): Builder
    {
        return $team
            ? SalesDcr::query()->applyVisibility('SalesDcr', $actor)->where('user_id', '!=', $actor->id)
            : SalesDcr::query()->where('user_id', $actor->id);
    }

    private function query(User $actor, bool $team): Builder
    {
        $this->authorizeView($actor);

        return SalesDcrExpense::query()->whereIn('sales_dcr_id', $this->reports($actor, $team)->select('id'))
            ->when($team, fn (Builder $rows): Builder => $rows->applyVisibility('SalesDcrExpense', $actor));
    }

    private function lockReports(User $actor, bool $team, array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        $reports = $this->reports($actor, $team)->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($reports->count() === count($ids), 404);

        return $reports;
    }

    private function withDetails(Builder $query): Builder
    {
        return $query->with(['salesDcr.user:id,name', 'salesDcr.approval', 'expenseType:id,name', 'transportMode:id,name'])
            ->withExists([
                'receipts' => fn (Builder $rows): Builder => $rows->withTrashed(),
                'items' => fn (Builder $rows): Builder => $rows->withTrashed(),
            ]);
    }

    private function assertManual(SalesDcrExpense $expense): void
    {
        abort_if($expense->is_auto_calculated, 422, 'Auto-calculated expenses use the DCR calculation.');
    }

    private function assertNoEvidence(SalesDcrExpense $expense): void
    {
        abort_if(isset($expense->meta['operation_id']) || $expense->receipts()->withTrashed()->exists()
            || $expense->items()->withTrashed()->exists(), 422, 'Expenses with receipts, line items or offline history must stay on their original report.');
    }

    private function assertNoPendingWork(SalesDcr $report): void
    {
        app(FieldSyncRecoveryService::class)->assertNoPendingReview($report);
        abort_if(app(FieldDeviceService::class)->blockingDevices($report)->isNotEmpty(), 422, 'Synchronize pending work for the report date first.');
    }

    private function reportLabel(SalesDcr $report): string
    {
        return "#{$report->id} · ".$report->dcr_date?->toDateString().' · '.$report->user?->name;
    }

    private function revision(SalesDcrExpense $expense): string
    {
        return hash('sha256', json_encode(Arr::only($expense->getAttributes(), [...self::FIELDS, 'id', 'updated_at', 'deleted_at']), JSON_THROW_ON_ERROR));
    }

    private function summary(User $actor, SalesDcrExpense $expense, VisitPreference $preferences): array
    {
        $report = $expense->salesDcr;
        $writable = ! $expense->is_auto_calculated && $preferences->allowsDcrDate($report->dcr_date)
            && in_array($report->approval_status, ['draft', 'rejected'], true)
            && ! in_array($report->approval?->approval_status, ['pending', 'approved'], true);
        $evidence = $expense->receipts_exists || $expense->items_exists || isset($expense->meta['operation_id']);
        $actions = $expense->trashed() ? ['restore', ...($evidence ? [] : ['forceDelete'])] : ['delete'];
        $editable = $writable && ! $expense->trashed() && $actor->can('update', $expense);

        return [
            'id' => $expense->id, 'sales_dcr_id' => $report->id,
            'report' => ['id' => $report->id, 'label' => $this->reportLabel($report), 'date' => $report->dcr_date?->toDateString()],
            'dcr_date' => $report->dcr_date?->toDateString(),
            'expense_type' => ['id' => $expense->expense_type_id, 'label' => $expense->expenseType?->name],
            'transport_mode' => $expense->transport_mode_id ? ['id' => $expense->transport_mode_id, 'label' => $expense->transportMode?->name] : null,
            'amount' => (float) $expense->amount,
            'quantity' => $expense->quantity !== null ? (float) $expense->quantity : null,
            'rate' => $expense->rate !== null ? (float) $expense->rate : null,
            'remarks' => $expense->remarks, 'is_auto_calculated' => $expense->is_auto_calculated,
            'has_receipts' => (bool) $expense->receipts_exists, 'trashed' => $expense->trashed(),
            'revision' => $this->revision($expense),
            'can_edit' => $editable, 'can_move' => $editable && ! $evidence,
            'actions' => $writable ? array_values(array_filter($actions, fn (string $action): bool => $actor->can($action, $expense))) : [],
        ];
    }
}
