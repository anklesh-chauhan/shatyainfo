<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreVisitPhotoRequest;
use App\Http\Requests\Api\V1\VisitCheckInRequest;
use App\Http\Requests\Api\V1\VisitCheckOutRequest;
use App\Http\Resources\Api\V1\MediaResource;
use App\Http\Resources\Api\V1\VisitResource;
use App\Services\Field\FieldVisitService;
use App\Services\Field\FieldVisitWorkspaceService;
use App\Services\Visit\VisitEnforcementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    public function __construct(
        private FieldVisitService $visitService,
        private VisitEnforcementService $enforcement,
    ) {}

    public function options(Request $request, FieldVisitWorkspaceService $workspace): JsonResponse
    {
        return response()->json(['data' => $workspace->options($request->user())]);
    }

    public function openPlanned(Request $request, int $detail): VisitResource
    {
        $data = $request->validate([
            'patch_id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],
        ]);
        $visit = $this->visitService->openPlanned(
            $request->user(), $detail, $data['patch_id'], $data['company_id'],
        );

        return new VisitResource($visit);
    }

    public function actPlanned(Request $request, int $detail): VisitResource
    {
        $data = $request->validate([
            'patch_id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],
            'action' => ['required', 'in:cancel,reschedule'],
            'reason' => ['required', 'string', 'max:2000'],
            'rescheduled_for' => ['required_if:action,reschedule', 'nullable', 'date_format:Y-m-d', 'after:today'],
        ]);
        $visit = $this->visitService->actPlanned(
            $request->user(), $detail, $data['patch_id'], $data['company_id'],
            $data['action'], $data['reason'], $data['rescheduled_for'] ?? null,
        );

        return new VisitResource($visit);
    }

    public function show(Request $request, int $visit, FieldVisitWorkspaceService $workspace): VisitResource
    {
        $record = $this->visitService->findOwned($request->user(), $visit);
        $workspace->load($record);
        $record->needs_check_in_image = $this->enforcement->needsCheckInImage($record);
        $record->needs_check_out_image = $this->enforcement->needsCheckOutImage($record);

        return new VisitResource($record);
    }

    public function update(Request $request, int $visit, FieldVisitWorkspaceService $workspace): VisitResource
    {
        $data = $request->validate([
            'visit_outcome_id' => ['sometimes', 'nullable', 'integer'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'visit_purpose_ids' => ['sometimes', 'array'],
            'visit_purpose_ids.*' => ['integer', 'distinct'],
            'is_joint_work' => ['sometimes', 'boolean'],
            'joint_user_ids' => ['sometimes', 'array'],
            'joint_user_ids.*' => ['integer', 'distinct'],
            'next_follow_up_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'feedback' => ['sometimes', 'array'],
            'feedback.*.question_id' => ['required', 'integer', 'distinct'],
            'feedback.*.answer' => ['nullable', 'integer', 'between:1,5'],
        ]);
        $record = $this->workspaceVisit($request, $visit);
        $record = $workspace->save($request->user(), $record, $data);
        $record->needs_check_in_image = $this->enforcement->needsCheckInImage($record);
        $record->needs_check_out_image = $this->enforcement->needsCheckOutImage($record);

        return new VisitResource($record);
    }

    public function createDocument(Request $request, int $visit, FieldVisitWorkspaceService $workspace): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:quote,sales_order']]);
        $record = $this->workspaceVisit($request, $visit);

        return response()->json(['data' => $workspace->createDocument($request->user(), $record, $data['type'])]);
    }

    private function workspaceVisit(Request $request, int $visit): \App\Models\Visit
    {
        return $this->visitService->findOwned($request->user(), $visit);
    }

    public function checkIn(VisitCheckInRequest $request, int $visit): VisitResource
    {
        $record = $this->visitService->findOwned($request->user(), $visit);
        $record = $this->visitService->checkIn($request->user(), $record, $request->validated());
        $record->needs_check_in_image = $this->enforcement->needsCheckInImage($record);
        $record->needs_check_out_image = $this->enforcement->needsCheckOutImage($record);

        return new VisitResource($record);
    }

    public function checkOut(VisitCheckOutRequest $request, int $visit): JsonResponse
    {
        $record = $this->visitService->findOwned($request->user(), $visit);
        $result = $this->visitService->checkOut($request->user(), $record, $request->validated());

        $visitModel = $result['visit'];
        $visitModel->needs_check_in_image = $this->enforcement->needsCheckInImage($visitModel);
        $visitModel->needs_check_out_image = $result['needs_checkout_image']
            || $this->enforcement->needsCheckOutImage($visitModel);

        return response()->json([
            'data' => new VisitResource($visitModel),
            'meta' => [
                'needs_checkout_image' => $result['needs_checkout_image'],
            ],
        ]);
    }

    public function storePhoto(StoreVisitPhotoRequest $request, int $visit): MediaResource
    {
        $record = $this->visitService->findOwned($request->user(), $visit);

        $media = $this->visitService->storePhoto(
            $request->user(),
            $record,
            $request->file('photo'),
            $request->validated(),
        );

        return new MediaResource($media);
    }
}
