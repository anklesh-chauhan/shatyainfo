<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LeaveApplication;
use App\Services\Field\FieldLeaveService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveApplication
 */
class LeaveApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_date' => optional($this->from_date)?->toDateString(),
            'to_date' => optional($this->to_date)?->toDateString(),
            'total_days' => $this->total_days !== null ? (float) $this->total_days : 0.0,
            'is_half_day' => (bool) $this->is_half_day,
            'half_day_type' => $this->half_day_type,
            'approval_status' => $this->approval_status,
            'reason' => $this->reason,
            'payroll_locked' => (bool) $this->payroll_locked,
            'can_cancel' => FieldLeaveService::canCancel($this->resource),
            'leave_type' => $this->whenLoaded('leaveType', fn (): ?array => $this->leaveType ? [
                'id' => $this->leaveType->id,
                'code' => $this->leaveType->code,
                'name' => $this->leaveType->name,
            ] : null),
        ];
    }
}
