<?php

namespace App\Services\Field;

use App\Models\FlexiblePlanEntry;
use App\Models\Patch;
use App\Models\SalesTourPlanDetail;
use App\Models\Visit;
use Illuminate\Validation\ValidationException;

class FieldVisitPlanningPolicy
{
    /**
     * Validate a visit against an explicit policy snapshot. Authorization and
     * customer visibility must still be checked by the calling workflow.
     *
     * @param  array<string, bool|string>  $rules
     */
    public function validate(Visit $visit, array $rules, ?string $unplannedReason = null): void
    {
        $errors = [];
        foreach (['territory', 'patch'] as $field) {
            if (($rules['require_'.$field] ?? false) && ! $visit->{$field.'_id'}) {
                $errors[$field.'_id'] = 'Select a '.$field.' for this company policy.';
            }
        }
        if ($visit->patch_id) {
            $patch = Patch::query()->find($visit->patch_id);
            if (! $patch || ! $visit->territory_id || (int) $patch->territory_id !== (int) $visit->territory_id) {
                $errors['patch_id'] = 'Select a patch in the visit territory.';
            }
        }
        if ($visit->exists && $visit->isDirty(['flexible_plan_entry_id', 'flexible_plan_version'])) {
            $errors['flexible_plan_entry_id'] = 'A captured visit cannot be linked to a different intention or version.';
        }
        if ($visit->flexible_plan_entry_id) {
            $entry = FlexiblePlanEntry::query()->find($visit->flexible_plan_entry_id);
            if (! ($rules['enable_flexible_plans'] ?? false) || ! $entry || $entry->status !== 'active'
                || (int) $entry->user_id !== (int) $visit->employee_id
                || $entry->date->toDateString() !== $visit->visit_date?->toDateString()
                || (int) $entry->version !== (int) $visit->flexible_plan_version
                || (int) $entry->patch_id !== (int) $visit->patch_id
                || $visit->sales_tour_plan_id || $visit->sales_tour_plan_detail_id || $visit->visit_type !== 'planned') {
                $errors['flexible_plan_entry_id'] = 'Select your current customer intention matching this visit date and version.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return;
        }
        $hasPlan = $visit->sales_tour_plan_id || $visit->sales_tour_plan_detail_id;
        if ($hasPlan) {
            $detail = SalesTourPlanDetail::query()->with('tourPlan')->find($visit->sales_tour_plan_detail_id);
            if (! $detail || ! $detail->tourPlan
                || (int) $detail->sales_tour_plan_id !== (int) $visit->sales_tour_plan_id
                || (int) $detail->tourPlan->user_id !== (int) $visit->employee_id
                || $detail->date?->toDateString() !== $visit->visit_date?->toDateString()) {
                $errors['sales_tour_plan_detail_id'] = 'Select your own tour day matching the visit date and plan.';
            } else {
                if (($rules['require_approved_plan'] ?? false) && $detail->tourPlan->approval_status !== 'approved') {
                    $errors['sales_tour_plan_id'] = 'The monthly tour plan must be approved before execution.';
                }
                if ($visit->patch_id && ! $detail->patches()->whereKey($visit->patch_id)->exists()) {
                    $errors['patch_id'] = 'Select a patch included in the tour day.';
                }
            }
        } else {
            $handling = $rules['unplanned_calls'] ?? 'block';
            if ($handling === 'block') {
                $errors['sales_tour_plan_id'] = 'Unplanned calls are disabled by company policy.';
            } elseif ($handling === 'allow_with_reason' && blank($unplannedReason)) {
                $errors['unplanned_reason'] = 'Explain the reason for this unplanned call.';
            }
            if ($visit->visit_type === 'planned') {
                $errors['visit_type'] = 'A planned visit must reference its tour day.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
