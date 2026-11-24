<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DecideMyApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\SalesDcr;
use App\Services\Approval\ApprovalService;
use App\Services\Field\DcrRegisterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessBusinessPage('View:MyApprovals'), 403);

        return response()->json($this->assignedSteps($request)
            ->currentFor($request->user()->id)
            ->with('approval.requestedBy:id,name')
            ->latest('id')->paginate(25)->through($this->summary(...)));
    }

    public function show(Request $request, int $step, DcrRegisterService $dcrs): JsonResponse
    {
        abort_unless($request->user()->canAccessBusinessPage('View:MyApprovals'), 403);
        $record = $this->assignedSteps($request)->currentFor($request->user()->id)
            ->with('approval.requestedBy:id,name')->findOrFail($step);

        $data = $this->summary($record);
        if ($record->approval->approvable_type === (new SalesDcr)->getMorphClass()) {
            $scope = $dcrs->readableScope($request->user(), (int) $record->approval->approvable_id);
            if ($scope !== null) {
                $data['dcr_scope'] = $scope;
            }
        }

        return response()->json(['data' => $data]);
    }

    public function decide(DecideMyApprovalRequest $request, int $step, ApprovalService $approvals): JsonResponse
    {
        $record = $this->assignedSteps($request)->with('approval')->findOrFail($step);
        abort_unless($record->approval, 404);
        $data = $request->validated();
        $accepted = $data['decision'] === 'approve'
            ? $approvals->approve($record->approval, $request->user()->id, $data['comments'] ?? null, $step)
            : $approvals->reject($record->approval, $request->user()->id, $data['comments'], $step);

        abort_unless($accepted, 409, 'This request has changed. Refresh My Approvals.');

        return response()->json(['data' => ['id' => $step, 'decision' => $data['decision']]]);
    }

    private function assignedSteps(Request $request): Builder
    {
        return ApprovalStep::query()->availableOnMobile()
            ->where('assigned_user_id', $request->user()->id);
    }

    /** @return array<string, mixed> */
    private function summary(ApprovalStep $step): array
    {
        $approval = $step->approval;
        $snapshot = $approval->submitted_record_summary ?? [];

        return [
            'id' => $step->id,
            'module' => class_basename($approval->approvable_type),
            'record_id' => $approval->approvable_id,
            'document_number' => $snapshot['document_number'] ?? null,
            'flow' => $snapshot['flow_name'] ?? null,
            'requested_by' => $approval->requestedBy?->name,
            'requested_at' => $approval->created_at?->toIso8601String(),
            'due_at' => $step->due_at?->toIso8601String(),
            'step_order' => $step->step_order,
            'requested_amount' => $approval->requested_amount,
            'context' => array_intersect_key($snapshot['attributes'] ?? [], array_flip(['month', 'visit_date', 'dcr_date', 'start_date', 'end_date'])),
        ];
    }
}
