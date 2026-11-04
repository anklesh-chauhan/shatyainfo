<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\AccountReportingMaster;
use App\Models\FieldDcrEntry;
use App\Models\ItemMaster;
use App\Models\RcpaCompetitor;
use App\Models\RcpaObservation;
use App\Models\SalesDcr;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldRcpaService
{
    /**
     * Create a new class instance.
     */
    public function options(User $user): array
    {
        abort_unless($user->can('Capture:Rcpa') || $user->can('View:Rcpa'), 403);
        $chemists = AccountMaster::query()->applyVisibility('AccountMaster')->whereIn('rcpa_tier', ['A', 'B'])->orderBy('name')->get();
        $last = RcpaObservation::query()->whereIn('chemist_id', $chemists->pluck('id'))->whereDate('observed_on', '<=', today())
            ->select('chemist_id')->selectRaw('MAX(observed_on) as last_observed_on')->groupBy('chemist_id')->pluck('last_observed_on', 'chemist_id');

        return ['data_flag' => 'ESTIMATED_FIELD',
            'chemists' => $chemists->map(function ($chemist) use ($last): array {
                $date = $last[$chemist->id] ?? null;
                $due = $date ? CarbonImmutable::parse($date)->addDays($chemist->rcpa_tier === 'A' ? 7 : 14)->toDateString() : null;

                return ['id' => $chemist->id, 'name' => $chemist->name, 'tier' => $chemist->rcpa_tier,
                    'territory_id' => $chemist->territory_id, 'last_observed_on' => $date, 'next_due_on' => $due,
                    'is_due' => $due === null || $due <= today()->toDateString()];
            })->values(),
            'segments' => AccountReportingMaster::query()->where('kind', 'therapy_area')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'competitors' => RcpaCompetitor::query()->where('is_active', true)->orderBy('therapy_area_id')->orderBy('rank')->get(['id', 'therapy_area_id', 'rank', 'name']),
            'items' => ItemMaster::query()->orderBy('item_name')->get(['id', 'item_code', 'item_name'])];
    }

    public function assertVisitedChemistsCurrent(SalesDcr $dcr): void
    {
        foreach ($dcr->visits()->where('visit_status', '!=', 'cancelled')->with('visitables.visitable')->get() as $visit) {
            $chemist = $visit->primaryCompany();
            if (! in_array($chemist?->rcpa_tier, ['A', 'B'], true)) {
                continue;
            }
            $last = RcpaObservation::query()->where('chemist_id', $chemist->id)->whereDate('observed_on', '<=', $dcr->dcr_date)->max('observed_on');
            if (! $last || CarbonImmutable::parse($last)->addDays($chemist->rcpa_tier === 'A' ? 7 : 14)->lte($dcr->dcr_date)) {
                throw ValidationException::withMessages(['rcpa' => 'RCPA is due for visited chemist '.$chemist->name.'. Record and synchronize it before submitting this DCR.']);
            }
        }
    }

    public function capture(User $user, array $data): RcpaObservation
    {
        abort_unless($user->can('Capture:Rcpa'), 403);

        return DB::transaction(function () use ($user, $data): RcpaObservation {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $time = FieldRecordedAt::resolve(['recorded_at' => $data['captured_at']]);
            abort_unless($time->toDateString() === $data['observed_on'], 422, 'RCPA capture date must match the observed day.');
            $chemist = AccountMaster::query()->applyVisibility('AccountMaster')->whereIn('rcpa_tier', ['A', 'B'])->findOrFail($data['chemist_id']);
            abort_unless($user->can('view', $chemist), 403);
            abort_unless(AccountReportingMaster::query()->where('kind', 'therapy_area')->where('is_active', true)->whereKey($data['therapy_area_id'])->exists(), 422, 'Select an active therapeutic segment.');
            $brands = RcpaCompetitor::query()->where('therapy_area_id', $data['therapy_area_id'])->where('is_active', true)->get()->keyBy('id');
            abort_unless($brands->count() === 5 && $brands->keys()->sort()->values()->all() === collect($data['competitors'])->pluck('competitor_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
                422, 'Refresh and report presence for all five configured competitors in this segment.');
            $competitors = array_map(fn ($row) => [...$row, 'name' => $brands[$row['competitor_id']]->name], $data['competitors']);
            $dcrService = app(FieldDcrService::class);
            $dcr = $dcrService->forDate($user, $data['observed_on']);
            $dcr = $dcr->newQuery()->lockForUpdate()->findOrFail($dcr->id);
            $dcrService->assertEditable($dcr);
            $record = RcpaObservation::query()->create(['event_id' => $data['event_id'], 'user_id' => $user->id,
                'chemist_id' => $chemist->id, 'territory_id' => $chemist->territory_id, 'chemist_tier' => $chemist->rcpa_tier,
                'sales_dcr_id' => $dcr->id, 'therapy_area_id' => $data['therapy_area_id'], 'item_master_id' => $data['item_master_id'],
                'observed_on' => $data['observed_on'], 'stock_status' => $data['stock_status'], 'doctor_rx_share' => $data['doctor_rx_share'],
                'offtake_quantity' => $data['offtake_quantity'], 'offtake_days' => $data['offtake_days'],
                'competitors' => $competitors, 'data_flag' => 'ESTIMATED_FIELD', 'remarks' => $data['remarks'] ?? null,
                'captured_at' => $time, 'received_at' => now()]);
            FieldDcrEntry::query()->create(['event_id' => $data['event_id'], 'user_id' => $user->id, 'sales_dcr_id' => $dcr->id,
                'category' => 'rcpa', 'remarks' => 'RCPA: '.$chemist->name.' [ESTIMATED_FIELD]', 'captured_at' => $time, 'received_at' => now()]);

            return $record;
        });
    }
}
