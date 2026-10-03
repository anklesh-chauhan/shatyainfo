<?php

namespace App\Services\Field;

use App\Models\ItemMaster;
use App\Models\SgipDistribution;
use App\Models\SgipDistributionItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\SGIPComplianceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldSgipService
{
    public function __construct(
        private FieldVisitService $visitService,
    ) {}

    public function show(User $user, Visit $visit): ?SgipDistribution
    {
        $this->visitService->findOwned($user, $visit->id);

        return $visit->sgipDistribution()
            ->with(['items.item', 'doctor', 'territory'])
            ->first();
    }

    /**
     * @param  array{items: list<array{item_master_id: int, quantity: float|int|string, unit_value?: float|int|string|null}>, sample_issue_id?: int|null, marketing_campaign_id?: int|null, remarks?: string|null}  $data
     */
    public function upsert(User $user, Visit $visit, array $data): SgipDistribution
    {
        $visit = $this->visitService->findOwned($user, $visit->id);

        $account = $visit->primaryCompany();

        if (! $account) {
            throw ValidationException::withMessages([
                'visit' => 'Select a customer on the visit before creating SGIP distribution.',
            ]);
        }

        $employeeId = $user->employee?->id ?? $user->employee_id;

        if (! $employeeId) {
            throw ValidationException::withMessages([
                'employee' => 'Employee not linked to this user.',
            ]);
        }

        return DB::transaction(function () use ($user, $visit, $account, $employeeId, $data): SgipDistribution {
            if (array_key_exists('expected_updated_at', $data)) {
                $existing = SgipDistribution::query()->where('visit_id', $visit->id)->lockForUpdate()->first();
                $expected = $data['expected_updated_at'];
                $matches = $existing
                    ? ($expected !== null && $existing->updated_at->equalTo(CarbonImmutable::parse($expected)))
                    : $expected === null;
                abort_unless($matches, 409, 'SGIP changed on the server. Refresh and review before saving.');
            }
            $distribution = SgipDistribution::query()->firstOrCreate(
                ['visit_id' => $visit->id],
                [
                    'user_id' => $user->id,
                    'employee_id' => $employeeId,
                    'account_master_id' => $account->id,
                    'territory_id' => $visit->territory_id,
                    'sales_tour_plan_id' => $visit->sales_tour_plan_id,
                    'visit_date' => $visit->visit_date ?? today(),
                    'approval_status' => 'draft',
                ],
            );

            if ($distribution->approval_status !== 'draft') {
                throw ValidationException::withMessages([
                    'sgip' => 'Only draft SGIP distributions can be updated.',
                ]);
            }

            $distribution->fill([
                'user_id' => $user->id,
                'employee_id' => $employeeId,
                'account_master_id' => $account->id,
                'territory_id' => $visit->territory_id,
                'sales_tour_plan_id' => $visit->sales_tour_plan_id,
                'visit_date' => $visit->visit_date ?? today(),
                'sample_issue_id' => $data['sample_issue_id'] ?? $distribution->sample_issue_id,
                'marketing_campaign_id' => $data['marketing_campaign_id'] ?? $distribution->marketing_campaign_id,
            ])->save();

            $distribution->items()->get()->each->forceDelete();

            foreach ($data['items'] as $itemData) {
                $item = ItemMaster::query()->findOrFail($itemData['item_master_id']);
                $unitValue = $itemData['unit_value'] ?? $item->selling_price ?? 0;

                SgipDistributionItem::query()->create([
                    'sgip_distribution_id' => $distribution->id,
                    'item_master_id' => $item->id,
                    'quantity' => $itemData['quantity'],
                    'unit_value' => $unitValue,
                ]);
            }

            $distribution->recalculateTotal();

            return $distribution->fresh(['items.item', 'doctor', 'territory']);
        });
    }

    public function submit(User $user, Visit $visit): SgipDistribution
    {
        $visit = $this->visitService->findOwned($user, $visit->id);

        $distribution = $visit->sgipDistribution()->with('items')->first();

        if (! $distribution) {
            throw ValidationException::withMessages([
                'sgip' => 'SGIP distribution not found for this visit.',
            ]);
        }

        if ($distribution->approval_status !== 'draft') {
            throw ValidationException::withMessages([
                'sgip' => 'Only draft SGIP distributions can be submitted.',
            ]);
        }

        if ($distribution->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one item before submitting.',
            ]);
        }

        SGIPComplianceService::validate($distribution, true);

        $distribution->update(['approval_status' => 'submitted']);

        return $distribution->fresh(['items.item', 'doctor', 'territory']);
    }
}
