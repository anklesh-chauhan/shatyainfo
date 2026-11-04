<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\DailyAttendance;
use App\Models\SalesInvoice;
use App\Models\Territory;
use App\Models\TerritorySalesAssignment;
use App\Models\TerritorySalesTarget;
use App\Models\User;
use App\Services\TerritoryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesPerformanceService
{
    /**
     * Create a new class instance.
     */
    public function visibleTargets(User $user): Builder
    {
        abort_unless($user->can('View:SalesTargets') || $user->can('Manage:SalesTargets'), 403);
        $query = TerritorySalesTarget::query()->with('territory:id,name');
        if ($this->allTerritories($user)) {
            return $query;
        }
        $ids = $this->currentTerritoryIds($user);

        return $query->where(fn ($query) => $query->whereIn('territory_id', $ids)->orWhereExists(function ($assignment) use ($user): void {
            $assignment->selectRaw('1')->from('territory_sales_assignments')->whereColumn('territory_id', 'territory_sales_targets.territory_id')
                ->where('user_id', $user->id)->whereColumn('effective_from', '<=', 'territory_sales_targets.ends_on')
                ->where(fn ($until) => $until->whereNull('effective_to')->orWhereColumn('effective_to', '>=', 'territory_sales_targets.starts_on'));
        }));
    }

    private function allTerritories(User $user): bool
    {
        return $user->can('AccessAllRecords') || $user->hasRole(['super_admin', 'administration_admin']);
    }

    private function currentTerritoryIds(User $user): array
    {
        return array_unique([...TerritoryService::getUserTerritoryIds($user),
            ...AccountMaster::query()->applyVisibility('AccountMaster')->whereNotNull('territory_id')->pluck('territory_id')->all(),
            ...TerritorySalesAssignment::query()->where('user_id', $user->id)->whereDate('effective_from', '<=', today())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))->pluck('territory_id')->all()]);
    }

    public function territories(User $user): Builder
    {
        abort_unless($user->can('View:SalesTargets') || $user->can('Manage:SalesTargets'), 403);
        $query = Territory::query()->orderBy('name');
        if (! $this->allTerritories($user)) {
            $query->whereIn('id', $this->currentTerritoryIds($user));
        }

        return $query;
    }

    public function createTarget(User $user, array $data): TerritorySalesTarget
    {
        abort_unless($user->can('Manage:SalesTargets'), 403);
        $territory = $this->territories($user)->findOrFail($data['territory_id']);
        $from = CarbonImmutable::parse($data['starts_on'])->startOfDay();
        $months = match ($data['period_type']) {
            'monthly' => 1, 'quarterly' => 3, 'annual' => 12, default => abort(422, 'Choose a supported target period.')
        };
        abort_unless($from->day === 1, 422, 'Target periods start on the first day of a month.');
        $to = $from->addMonths($months)->subDay();

        return DB::transaction(function () use ($user, $data, $territory, $from, $to): TerritorySalesTarget {
            $territory->newQuery()->whereKey($territory->id)->lockForUpdate()->firstOrFail();
            abort_if(TerritorySalesTarget::query()->where('territory_id', $territory->id)->where('period_type', $data['period_type'])
                ->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->exists(), 409, 'This territory already has an overlapping target for that period type.');

            return TerritorySalesTarget::query()->create(['territory_id' => $territory->id, 'period_type' => $data['period_type'],
                'starts_on' => $from, 'ends_on' => $to, ...$this->targetValues($data), 'created_by' => $user->id, 'revisions' => []]);
        });
    }

    private function targetValues(array $data): array
    {
        $value = (string) $data['target_value_lakhs'];
        abort_unless(preg_match('/^\d{1,8}(\.\d{1,7})?$/', $value), 422, 'Enter the value target in INR lakhs (up to seven decimal places).');
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        $paise = (int) $whole * 10000000 + (int) str_pad($fraction, 7, '0');
        abort_unless($paise > 0 && is_numeric($data['target_quantity']) && round((float) $data['target_quantity'], 6) > 0
            && (float) $data['target_quantity'] <= 999999999999 && trim($data['note']) !== '', 422, 'Set positive value and base-unit quantity targets with a planning note.');

        return ['target_value_paise' => $paise, 'target_quantity' => round((float) $data['target_quantity'], 6), 'note' => $data['note']];
    }

    public function reviseTarget(User $user, TerritorySalesTarget $target, array $data): TerritorySalesTarget
    {
        abort_unless($user->can('Manage:SalesTargets'), 403);
        $this->territories($user)->findOrFail($target->territory_id);

        return DB::transaction(function () use ($target, $data): TerritorySalesTarget {
            $target = TerritorySalesTarget::query()->lockForUpdate()->findOrFail($target->id);
            $target->update($this->targetValues($data));

            return $target;
        });
    }

    public function assign(User $user, array $data): TerritorySalesAssignment
    {
        abort_unless($user->can('Manage:SalesTargets'), 403);
        $territory = $this->territories($user)->findOrFail($data['territory_id']);
        $owner = User::query()->findOrFail($data['user_id']);
        $from = CarbonImmutable::parse($data['effective_from'])->startOfDay();

        return DB::transaction(function () use ($territory, $owner, $from, $data, $user): TerritorySalesAssignment {
            $territory->newQuery()->whereKey($territory->id)->lockForUpdate()->firstOrFail();
            $last = TerritorySalesAssignment::query()->where('territory_id', $territory->id)->latest('effective_from')->lockForUpdate()->first();
            if ($last) {
                abort_if($from->lt(today()) || $from->lte($last->effective_from), 422, 'Append assignments chronologically from today onward. Historical ownership is preserved.');
                abort_if((int) $last->user_id === (int) $owner->id, 409, 'This representative already owns the latest assignment.');
                $last->update(['effective_to' => $from->subDay()]);
            }

            return TerritorySalesAssignment::query()->create(['territory_id' => $territory->id, 'user_id' => $owner->id,
                'employee_id' => $owner->employee?->id ?? $owner->employee_id, 'effective_from' => $from,
                'created_by' => $user->id, 'reason' => $data['reason']]);
        });
    }

    public function dashboard(User $user, TerritorySalesTarget $target): array
    {
        $this->visibleTargets($user)->findOrFail($target->id);
        $until = $target->ends_on->min(CarbonImmutable::today());
        $totals = ['gross_paise' => 0, 'discount_paise' => 0, 'returns_paise' => 0, 'breakage_expiry_paise' => 0, 'net_paise' => 0, 'net_base_quantity' => 0.0];
        $months = [];
        $credits = [];
        $unallocated = 0;
        $unallocatedQuantity = 0.0;
        for ($month = $target->starts_on; $month->lte($until); $month = $month->addMonth()) {
            $end = $month->endOfMonth()->min($until);
            $ledger = app(SecondarySalesService::class)->summary($target->territory_id, $month->toDateString(), $end->toDateString());
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $ledger[$key];
            }
            $allocation = $this->allocateMonth($target->territory_id, $month, $end, $ledger['net_paise'], $ledger['net_base_quantity']);
            $unallocated += $allocation['unallocated_paise'];
            $unallocatedQuantity += $allocation['unallocated_base_quantity'];
            foreach ($allocation['credits'] as $credit) {
                $id = $credit['user_id'];
                $credits[$id] ??= ['user_id' => $id, 'name' => $credit['name'], 'value_paise' => 0, 'base_quantity' => 0.0];
                $credits[$id]['value_paise'] += $credit['value_paise'];
                $credits[$id]['base_quantity'] = round($credits[$id]['base_quantity'] + $credit['base_quantity'], 6);
            }
            $months[] = ['month' => $month->format('Y-m'), 'ledger' => $ledger, 'allocation' => $allocation];
        }
        $primary = $target->starts_on->lte($until) ? SalesInvoice::query()->where('territory_id', $target->territory_id)->where('approval_status', 'approved')
            ->whereIn('status', ['sent', 'accepted'])->where('currency', 'INR')->whereBetween('date', [$target->starts_on->toDateString(), $until->toDateString()])
            ->selectRaw('COALESCE(SUM(ROUND(total * 100)), 0) as amount')->first()->amount : 0;

        $trends = [];
        if ($target->starts_on->lte($until)) {
            for ($trend = $until->startOfMonth()->subMonths(5); $trend->lte($until); $trend = $trend->addMonth()) {
                $trends[] = ['month' => $trend->format('Y-m'), ...app(SecondarySalesService::class)->summary($target->territory_id, $trend->toDateString(), $trend->endOfMonth()->min($until)->toDateString())];
            }
        }
        $estimates = [];
        if ($user->can('View:Rcpa') || $user->can('Capture:Rcpa')) {
            $query = DB::table('rcpa_observations as observation')->leftJoin('item_masters as item', 'item.id', '=', 'observation.item_master_id')
                ->leftJoin('account_reporting_masters as segment', 'segment.id', '=', 'observation.therapy_area_id')
                ->where('observation.territory_id', $target->territory_id)->where('observation.data_flag', 'ESTIMATED_FIELD')
                ->whereBetween('observation.observed_on', [$target->starts_on->toDateString(), $until->toDateString()]);
            if ($user->can('View:Rcpa')) {
                $query->whereIn('observation.chemist_id', AccountMaster::query()->applyVisibility('AccountMaster')->select('id'));
            } else {
                $query->where('observation.user_id', $user->id);
            }
            $estimates = $query->select('observation.item_master_id', 'item.item_name', 'segment.name as segment_name')
                ->selectRaw('COUNT(*) as observation_count, AVG(observation.doctor_rx_share) as mean_doctor_rx_share, AVG(observation.offtake_quantity / observation.offtake_days) as estimated_daily_offtake')
                ->groupBy('observation.item_master_id', 'item.item_name', 'observation.therapy_area_id', 'segment.name')->get()
                ->map(fn ($row) => [...(array) $row, 'data_flag' => 'ESTIMATED_FIELD'])->all();
        }

        return ['target' => $this->targetData($target), 'basis' => 'verified_secondary_sales', 'as_of' => now()->toIso8601String(),
            'ledger' => $totals, 'value_achievement_percent' => round($totals['net_paise'] / $target->target_value_paise * 100, 2),
            'quantity_achievement_percent' => round($totals['net_base_quantity'] / (float) $target->target_quantity * 100, 2),
            'value_shortfall_paise' => max(0, $target->target_value_paise - $totals['net_paise']), 'quantity_shortfall' => max(0, round((float) $target->target_quantity - $totals['net_base_quantity'], 6)),
            'breakdowns' => app(SecondarySalesService::class)->breakdowns($target->territory_id, $target->starts_on->toDateString(), $until->toDateString()),
            'trends' => $trends, 'rcpa_comparison' => $estimates,
            'credits' => array_values($credits), 'unallocated_paise' => $unallocated, 'unallocated_base_quantity' => round($unallocatedQuantity, 6), 'months' => $months,
            'primary_sales_paise' => (int) $primary, 'primary_basis' => 'approved_sent_or_accepted_invoices_with_territory_snapshot',
            'incentive_basis' => '100_percent_secondary_sales'];
    }

    private function allocateMonth(int $territoryId, CarbonImmutable $from, CarbonImmutable $to, int $net, float $quantity): array
    {
        $rows = TerritorySalesAssignment::query()->with('user:id,name')->where('territory_id', $territoryId)->whereDate('effective_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))->orderBy('effective_from')->get();
        $empty = fn (string $reason) => ['state' => $reason, 'credits' => [], 'unallocated_paise' => $net, 'unallocated_base_quantity' => $quantity];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            if (! $rows->contains(fn ($row) => $row->effective_from->lte($day) && ($row->effective_to === null || $row->effective_to->gte($day)))) {
                return $empty('missing_ownership_history');
            }
        }
        $early = $rows->filter(fn ($row) => $row->effective_from->gt($from->startOfMonth()) && $row->effective_from->day < 10)->last();
        $eligible = $early ? $rows->filter(fn ($row) => $row->effective_from->gte($early->effective_from)) : $rows;
        $owners = $eligible->pluck('user_id')->unique()->values();
        if ($owners->count() === 1) {
            $owner = $eligible->last();

            return ['state' => $early ? 'early_transfer' : 'sole_owner', 'credits' => [['user_id' => $owner->user_id, 'name' => $owner->user?->name,
                'value_paise' => $net, 'base_quantity' => $quantity, 'share_percent' => 100, 'active_days' => null]], 'unallocated_paise' => 0, 'unallocated_base_quantity' => 0];
        }
        $weights = [];
        foreach ($eligible as $row) {
            $start = $row->effective_from->max($from);
            $end = ($row->effective_to ?? $to)->min($to);
            $weights[$row->user_id] ??= 0;
            if (! $row->employee_id) {
                return $empty('missing_active_days');
            }
            $attendance = DailyAttendance::query()->where('employee_id', $row->employee_id)->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])->get();
            if ($attendance->isEmpty()) {
                return $empty('missing_active_days');
            }
            foreach ($attendance->filter(fn ($day) => $day->first_punch_in !== null && ! $day->is_absent)->unique(fn ($day) => $day->attendance_date->toDateString()) as $day) {
                $weights[$row->user_id] += $day->is_half_day ? 1 : 2;
            }
        }
        $denominator = array_sum($weights);
        if ($denominator === 0) {
            return $empty('missing_active_days');
        }
        $shares = [];
        $remainders = [];
        foreach ($weights as $id => $weight) {
            $numerator = abs($net) * $weight;
            $shares[$id] = intdiv($numerator, $denominator);
            $remainders[$id] = $numerator % $denominator;
        }
        arsort($remainders);
        $remaining = abs($net) - array_sum($shares);
        foreach (array_keys($remainders) as $id) {
            if ($remaining-- <= 0) {
                break;
            } $shares[$id]++;
        }
        $result = [];
        $usedQuantity = 0.0;
        $names = $eligible->keyBy('user_id');
        foreach ($weights as $id => $weight) {
            $qty = round($quantity * $weight / $denominator, 6);
            $usedQuantity += $qty;
            $result[] = ['user_id' => $id, 'name' => $names[$id]->user?->name, 'value_paise' => ($net < 0 ? -1 : 1) * $shares[$id],
                'base_quantity' => $qty, 'share_percent' => round($weight / $denominator * 100, 4), 'active_days' => $weight / 2];
        }
        $result[array_key_last($result)]['base_quantity'] = round($result[array_key_last($result)]['base_quantity'] + $quantity - $usedQuantity, 6);

        return ['state' => 'active_day_split', 'credits' => $result, 'unallocated_paise' => 0, 'unallocated_base_quantity' => 0];
    }

    public function targetData(TerritorySalesTarget $target): array
    {
        $target->loadMissing('territory:id,name');

        return ['id' => $target->id, 'territory_id' => $target->territory_id, 'territory' => $target->territory,
            'period_type' => $target->period_type, 'starts_on' => $target->starts_on->toDateString(), 'ends_on' => $target->ends_on->toDateString(),
            'target_value_paise' => $target->target_value_paise, 'target_value_lakhs' => $target->target_value_paise / 10000000,
            'target_quantity' => (float) $target->target_quantity, 'note' => $target->note];
    }
}
