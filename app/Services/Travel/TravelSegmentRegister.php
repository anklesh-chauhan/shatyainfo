<?php

namespace App\Services\Travel;

use App\Models\AccountMaster;
use App\Models\CityPinCode;
use App\Models\Patch;
use App\Models\SalesDcr;
use App\Models\SalesTourPlanDetail;
use App\Models\TransportMode;
use App\Models\TravelSegment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class TravelSegmentRegister
{
    private const FIELDS = ['sales_dcr_id', 'visit_id', 'sales_tour_plan_detail_id', 'patch_id',
        'from_account_id', 'to_account_id', 'from_area_town_id', 'to_area_town_id', 'transport_mode_id', 'distance_km'];

    public function __construct(private TravelSegmentValidation $validation) {}

    public function index(User $actor, array $filters): array
    {
        $query = $this->query($actor);
        match ($filters['trash'] ?? 'active') {
            'only' => $query->onlyTrashed(),
            'with' => $query->withTrashed(),
            default => $query,
        };
        $this->filterOrigin($query, $filters['origin'] ?? null);
        $page = $this->withLabels($query)
            ->when($filters['sales_dcr_id'] ?? null, fn (Builder $rows, int $id): Builder => $rows->where('sales_dcr_id', $id))
            ->when($filters['search'] ?? null, fn (Builder $rows, string $term): Builder => $rows->where(function (Builder $matches) use ($term): void {
                foreach (['fromAccount' => 'name', 'toAccount' => 'name', 'fromAreaTown' => 'area_town', 'toAreaTown' => 'area_town'] as $relation => $column) {
                    $matches->orWhereHas($relation, fn (Builder $related): Builder => $related->whereLike($column, '%'.$term.'%'));
                }
                if (ctype_digit($term) && filter_var($term, FILTER_VALIDATE_INT) !== false) {
                    $matches->orWhere('sales_dcr_id', (int) $term);
                }
            }))
            ->latest('id')->paginate(25)->through(fn (TravelSegment $segment): array => $this->summary($actor, $segment));

        return ['data' => $page->items(), 'meta' => [
            'last_page' => $page->lastPage(),
            'can_create' => $actor->can('create', TravelSegment::class),
            'can_bulk_restore' => $actor->can('restoreAny', TravelSegment::class),
            'can_bulk_force_delete' => $actor->can('forceDeleteAny', TravelSegment::class),
        ]];
    }

    public function detail(User $actor, int $id): array
    {
        $segment = $this->withLabels($this->query($actor)->withTrashed())->findOrFail($id);

        return $this->summary($actor, $segment);
    }

    public function options(User $actor, array $filters): array
    {
        $this->authorizeView($actor);
        abort_unless($actor->can('Create:TravelSegment') || $actor->can('Update:TravelSegment'), 403);
        $kind = $filters['kind'];
        $search = $filters['search'] ?? '';
        $pattern = '%'.$search.'%';
        if ($kind === 'reports') {
            $query = $this->reports($actor)->whereIn('approval_status', ['draft', 'rejected'])->whereDoesntHave('approval',
                fn (Builder $approvals): Builder => $approvals->whereIn('approval_status', ['pending', 'approved']))->with('user:id,name')
                ->when($search !== '', fn (Builder $reports): Builder => $reports->where(function (Builder $rows) use ($search, $pattern): void {
                    $rows->whereHas('user', fn (Builder $users): Builder => $users->whereLike('name', $pattern));
                    if (ctype_digit($search) && filter_var($search, FILTER_VALIDATE_INT) !== false) {
                        $rows->orWhere('id', (int) $search);
                    }
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $search, $date)
                        && checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
                        $rows->orWhereDate('dcr_date', $search);
                    }
                }))->orderByDesc('dcr_date')->orderByDesc('id');
        } else {
            $id = $filters['sales_dcr_id'] ?? throw ValidationException::withMessages(['sales_dcr_id' => 'Select a daily call report first.']);
            $report = $this->reports($actor)->findOrFail($id);
            $this->validation->assertEditableReport($report);
            $patch = null;
            if (isset($filters['patch_id'])) {
                $patch = Patch::query()->where('territory_id', $report->territory_id ?? 0)->findOrFail($filters['patch_id']);
            }
            $query = match ($kind) {
                'visits' => $report->visits()->whereLike('document_number', $pattern)->orderBy('document_number'),
                'plan_details' => SalesTourPlanDetail::query()->where('sales_tour_plan_id', $report->sales_tour_plan_id ?? 0)
                    ->whereDate('date', $report->dcr_date),
                'patches' => Patch::query()->where('territory_id', $report->territory_id ?? 0)->whereLike('name', $pattern)->orderBy('name'),
                'accounts' => ($patch ? $patch->companies()->wherePivotNull('deleted_at') : AccountMaster::query())->applyVisibility('AccountMaster', $actor)
                    ->where(fn (Builder $rows): Builder => $rows->whereLike('account_masters.name', $pattern)->orWhereLike('account_code', $pattern))
                    ->orderBy('account_masters.name'),
                'towns' => CityPinCode::query()->with('city:id,name')->when($search !== '', fn (Builder $rows): Builder => $rows->searchLocation($search))->orderBy('area_town'),
                'modes' => TransportMode::query()->whereLike('name', $pattern)->orderBy('name'),
            };
            $query->orderBy($kind === 'accounts' ? 'account_masters.id' : 'id');
        }
        $page = $query->paginate(25)->through(fn (Model $row): array => [
            'id' => $row->id,
            'label' => match ($kind) {
                'reports' => $this->reportLabel($row),
                'visits' => $row->document_number,
                'plan_details' => $row->date?->toDateString(),
                'towns' => $row->full_location,
                default => $row->name,
            },
        ]);

        return ['data' => $page->items(), 'meta' => ['last_page' => $page->lastPage()]];
    }

    public function save(User $actor, array $data, ?int $id = null): array
    {
        $this->authorizeView($actor);
        if ($id === null) {
            abort_unless($actor->can('create', TravelSegment::class), 403);
        }

        return $actor->getConnection()->transaction(function () use ($actor, $data, $id): array {
            $previous = $id === null ? null : $this->query($actor)->findOrFail($id, ['id', 'sales_dcr_id']);
            $reports = $this->lockReports($actor, array_filter([(int) $data['sales_dcr_id'], $previous?->sales_dcr_id]));
            if ($previous) {
                $segment = $this->query($actor)->lockForUpdate()->findOrFail($id);
                abort_unless($actor->can('update', $segment), 403);
                abort_unless($previous->sales_dcr_id === $segment->sales_dcr_id
                    && hash_equals($this->revision($segment), $data['revision']), 409, 'This segment changed. Reload it before saving.');
                $this->assertManual($segment);
                $this->validation->assertEditableReport($reports->get($segment->sales_dcr_id));
                $values = [...$segment->only(self::FIELDS), ...$data];
            } else {
                $segment = new TravelSegment(['distance_source' => 'manual', 'is_auto_generated' => false]);
                $values = $data;
            }
            $this->validation->validate($values, $actor);
            foreach (['from_area_town_id' => CityPinCode::class, 'to_area_town_id' => CityPinCode::class, 'transport_mode_id' => TransportMode::class] as $field => $model) {
                if (isset($values[$field]) && ! $model::query()->whereKey($values[$field])->exists()) {
                    throw ValidationException::withMessages([$field => 'Choose an available option.']);
                }
            }
            $segment->fill(array_intersect_key($values, array_flip(self::FIELDS)))->save();

            return $this->summary($actor, $this->withLabels($this->query($actor))->findOrFail($segment->id));
        }, attempts: 3);
    }

    public function lifecycle(User $actor, string $action, array $ids): int
    {
        if (count($ids) > 1 && $action !== 'delete') {
            abort_unless($actor->can($action.'Any', TravelSegment::class), 403);
        }

        return $actor->getConnection()->transaction(function () use ($actor, $action, $ids): int {
            $query = $this->query($actor)->withTrashed()->whereKey($ids)->orderBy('id');
            $parents = (clone $query)->pluck('sales_dcr_id', 'id');
            abort_unless($parents->count() === count($ids), 404);
            $reports = $this->lockReports($actor, $parents->values()->all());
            $records = $query->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $segment) {
                abort_unless($parents->get($segment->id) === $segment->sales_dcr_id, 409, 'The segment changed. Refresh the register.');
                abort_unless($actor->can($action, $segment), 403);
                abort_unless($segment->trashed() === ($action !== 'delete'), 409, 'The segment changed. Refresh the register.');
                $this->assertManual($segment);
                $this->validation->assertEditableReport($reports->get($segment->sales_dcr_id));
                if ($action === 'restore') {
                    $this->validation->validate($segment->only(self::FIELDS), $actor);
                }
            }
            foreach ($records as $segment) {
                $changed = match ($action) {
                    'delete' => $segment->delete(),
                    'restore' => $segment->restore(),
                    'forceDelete' => $segment->forceDelete(),
                };
                abort_unless($changed, 409, 'The segment could not be updated.');
            }

            return $records->count();
        }, attempts: 3);
    }

    private function authorizeView(User $actor): void
    {
        abort_unless($actor->can(['ViewAny:TravelSegment', 'View:TravelSegment']), 403);
    }

    private function reports(User $actor): Builder
    {
        return SalesDcr::query()->applyVisibility('SalesDcr', $actor);
    }

    private function query(User $actor): Builder
    {
        $this->authorizeView($actor);

        return TravelSegment::query()->whereHas('salesDcr', fn (Builder $reports): Builder => $reports->applyVisibility('SalesDcr', $actor));
    }

    private function lockReports(User $actor, array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        $reports = $this->reports($actor)->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($reports->count() === count($ids), 404);

        return $reports;
    }

    private function withLabels(Builder $segments): Builder
    {
        return $segments->with(['salesDcr.user:id,name', 'salesDcr.approval', 'visit:id,document_number', 'salesTourPlanDetail:id,date',
            'patch:id,name', 'fromAccount:id,name', 'toAccount:id,name', 'fromAreaTown.city:id,name', 'toAreaTown.city:id,name', 'transportMode:id,name']);
    }

    private function filterOrigin(Builder $query, ?string $origin): void
    {
        if ($origin === null) {
            return;
        }
        if ($origin === 'imported') {
            $query->whereNotNull('import_reference');

            return;
        }
        $query->whereNull('import_reference')->where(function (Builder $rows) use ($origin): void {
            if ($origin === 'manual') {
                $rows->where('is_auto_generated', false)->where('distance_source', 'manual')->whereNull('gps_distance_km');
            } else {
                $rows->where('is_auto_generated', true)->orWhere('distance_source', '!=', 'manual')->orWhereNotNull('gps_distance_km');
            }
        });
    }

    private function origin(TravelSegment $segment): string
    {
        if ($segment->import_reference !== null) {
            return 'imported';
        }

        return $segment->is_auto_generated || $segment->distance_source !== 'manual' || $segment->gps_distance_km !== null ? 'derived' : 'manual';
    }

    private function assertManual(TravelSegment $segment): void
    {
        abort_unless($this->origin($segment) === 'manual', 422, 'Generated and imported segments are read-only here.');
    }

    private function reportLabel(SalesDcr $report): string
    {
        return "#{$report->id} · ".$report->dcr_date?->toDateString().' · '.$report->user?->name;
    }

    private function revision(TravelSegment $segment): string
    {
        return hash('sha256', json_encode($segment->getAttributes(), JSON_THROW_ON_ERROR));
    }

    private function summary(User $actor, TravelSegment $segment): array
    {
        $report = $segment->salesDcr;
        $origin = $this->origin($segment);
        $writable = $origin === 'manual' && in_array($report?->approval_status, ['draft', 'rejected'], true)
            && ! in_array($report->approval?->approval_status, ['pending', 'approved'], true);
        $actions = $segment->trashed() ? ['restore', 'forceDelete'] : ['delete'];
        $label = fn (?Model $row, string $attribute = 'name'): ?array => $row ? ['id' => $row->id, 'label' => $row->{$attribute}] : null;

        return [
            'id' => $segment->id,
            'report' => $report ? ['id' => $report->id, 'label' => $this->reportLabel($report), 'status' => $report->approval_status] : null,
            'visit' => $label($segment->visit, 'document_number'),
            'plan_detail' => $segment->salesTourPlanDetail ? [
                'id' => $segment->salesTourPlanDetail->id, 'label' => $segment->salesTourPlanDetail->date?->toDateString(),
            ] : null,
            'patch' => $label($segment->patch),
            'from_account' => $label($segment->fromAccount),
            'to_account' => $label($segment->toAccount),
            'from_town' => $label($segment->fromAreaTown, 'full_location'),
            'to_town' => $label($segment->toAreaTown, 'full_location'),
            'transport_mode' => $label($segment->transportMode),
            'distance_km' => (float) $segment->distance_km,
            'distance_source' => $segment->distance_source,
            'gps_distance_km' => $segment->gps_distance_km !== null ? (float) $segment->gps_distance_km : null,
            'origin' => $origin,
            'trashed' => $segment->trashed(),
            'revision' => $this->revision($segment),
            'can_edit' => $writable && ! $segment->trashed() && $actor->can('update', $segment),
            'actions' => $writable ? array_values(array_filter($actions, fn (string $action): bool => $actor->can($action, $segment))) : [],
        ];
    }
}
