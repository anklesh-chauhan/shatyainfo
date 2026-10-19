<?php

namespace App\Services\Travel;

use App\Models\Patch;
use App\Models\SalesDcr;
use App\Models\SalesTourPlanDetail;
use App\Models\Visit;
use Illuminate\Validation\ValidationException;

class TravelSegmentValidation
{
    public function validate(array $data): void
    {
        $dcr = SalesDcr::find($data['sales_dcr_id'] ?? null);
        if (! $dcr) {
            $this->fail('sales_dcr_id', 'Choose an accessible daily call report.');
        }
        if (in_array($dcr->approval_status, ['approved', 'pending', 'submitted'], true)) {
            $this->fail('sales_dcr_id', 'Travel cannot be changed for a submitted or approved report.');
        }
        if (! empty($data['visit_id']) && ! Visit::whereKey($data['visit_id'])->where('sales_dcr_id', $dcr->id)->exists()) {
            $this->fail('visit_id', 'The visit must belong to the selected report.');
        }
        if (! empty($data['sales_tour_plan_detail_id']) && (! $dcr->sales_tour_plan_id || ! SalesTourPlanDetail::whereKey($data['sales_tour_plan_detail_id'])->where('sales_tour_plan_id', $dcr->sales_tour_plan_id)->whereDate('date', $dcr->dcr_date)->exists())) {
            $this->fail('sales_tour_plan_detail_id', 'The plan detail must match the report plan and date.');
        }
        if (! empty($data['patch_id'])) {
            $patch = Patch::find($data['patch_id']);
            if (! $patch || (int) $patch->territory_id !== (int) $dcr->territory_id) {
                $this->fail('patch_id', 'The patch must belong to the report territory.');
            }
            foreach (['from_account_id', 'to_account_id'] as $field) {
                if (! empty($data[$field]) && ! $patch->companies()->where('account_masters.id', $data[$field])->exists()) {
                    $this->fail($field, 'The customer must be assigned to the selected patch.');
                }
            }
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
