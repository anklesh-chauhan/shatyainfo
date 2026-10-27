<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Models\VisitPurpose;
use App\Services\Field\FieldMtpService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyTourPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(FieldMtpService::class);
        $purposes = VisitPurpose::query()->whereIn('id', $this->details->pluck('visit_purpose_ids')->flatten()->filter())->pluck('name', 'id');
        $jointUsers = User::query()->whereIn('id', $this->details->pluck('joint_with')->flatten()->filter())->pluck('name', 'id');

        return [
            'id' => $this->id, 'month' => $this->month->format('Y-m'),
            'employee_name' => $this->user?->name, 'approval_status' => $service->status($this->resource),
            'manager_remarks' => $this->manager_remarks,
            'revision' => $service->revision($this->resource),
            'can_edit' => $service->canEdit($request->user(), $this->resource),
            'can_decide' => $service->canDecide($request->user(), $this->resource),
            'details' => $this->details->map(fn ($d) => [
                'date' => $d->date->toDateString(), 'territory_id' => $d->territory_id,
                'has_visits' => $d->visits_count > 0,
                'territory_name' => $d->territory?->name,
                'patch_ids' => $d->patches->pluck('id'), 'patch_names' => $d->patches->pluck('name'),
                'visit_type_id' => $d->visit_type_id, 'visit_type_name' => $d->visitType?->name,
                'visit_purpose_ids' => $d->visit_purpose_ids ?? [],
                'purpose_names' => collect($d->visit_purpose_ids ?? [])->map(fn ($id) => $purposes[$id] ?? 'Unavailable purpose'),
                'joint_with' => $d->joint_with ?? [],
                'joint_names' => collect($d->joint_with ?? [])->map(fn ($id) => $jointUsers[$id] ?? 'Unavailable employee'),
                'remarks' => $d->remarks,
            ]),
            'approval_steps' => $this->approval?->steps->map(fn ($s) => [
                'order' => $s->step_order, 'approver' => $s->approver?->name,
                'status' => $s->status->value, 'comments' => $s->comments,
            ]) ?? [],
        ];
    }
}
