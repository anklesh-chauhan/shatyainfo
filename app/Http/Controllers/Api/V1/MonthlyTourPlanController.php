<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListMonthlyTourPlansRequest;
use App\Http\Requests\Api\V1\MonthlyTourPlanActionRequest;
use App\Http\Requests\Api\V1\SaveMonthlyTourPlanRequest;
use App\Http\Resources\Api\V1\MonthlyTourPlanResource;
use App\Services\Field\FieldMtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MonthlyTourPlanController extends Controller
{
    public function index(ListMonthlyTourPlansRequest $request, FieldMtpService $service): AnonymousResourceCollection
    {
        $user = $request->user();
        $plans = $request->validated('scope') === 'team'
            ? $service->teamQuery($user)
            : $service->query($user);

        return MonthlyTourPlanResource::collection($plans->whereLike('month', $request->validated('month').'%')->orderByDesc('id')->paginate(20));
    }

    public function inbox(ListMonthlyTourPlansRequest $request, FieldMtpService $service): AnonymousResourceCollection
    {
        return MonthlyTourPlanResource::collection($service->query($request->user(), true)
            ->whereLike('month', $request->validated('month').'%')->orderBy('id')->paginate(20));
    }

    public function options(Request $request, FieldMtpService $service): JsonResponse
    {
        return response()->json(['data' => $service->options($request->user())]);
    }

    public function show(Request $request, int $plan, FieldMtpService $service): MonthlyTourPlanResource
    {
        $scope = $request->query('scope', 'personal');
        abort_unless(in_array($scope, ['personal', 'team'], true), 422, 'Choose personal or team plans.');

        return new MonthlyTourPlanResource($scope === 'team'
            ? $service->teamQuery($request->user())->findOrFail($plan)
            : $service->find($request->user(), $plan));
    }

    public function store(SaveMonthlyTourPlanRequest $request, FieldMtpService $service): MonthlyTourPlanResource
    {
        return new MonthlyTourPlanResource($service->save($request->user(), $request->validated()));
    }

    public function update(SaveMonthlyTourPlanRequest $request, int $plan, FieldMtpService $service): MonthlyTourPlanResource
    {
        return new MonthlyTourPlanResource($service->save($request->user(), $request->validated(), $plan));
    }

    public function submit(MonthlyTourPlanActionRequest $request, int $plan, FieldMtpService $service): MonthlyTourPlanResource
    {
        return new MonthlyTourPlanResource($service->submit($request->user(), $plan, $request->validated('revision')));
    }

    public function approve(MonthlyTourPlanActionRequest $request, int $plan, FieldMtpService $service): MonthlyTourPlanResource
    {
        return new MonthlyTourPlanResource($service->decide($request->user(), $plan, $request->validated(), true));
    }

    public function reject(MonthlyTourPlanActionRequest $request, int $plan, FieldMtpService $service): MonthlyTourPlanResource
    {
        return new MonthlyTourPlanResource($service->decide($request->user(), $plan, $request->validated(), false));
    }
}
