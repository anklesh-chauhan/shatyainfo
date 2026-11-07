<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListFlexiblePlansRequest;
use App\Http\Requests\Api\V1\SaveFlexiblePlanRequest;
use App\Services\Field\FieldFlexiblePlanningService;
use Illuminate\Http\JsonResponse;

class FlexiblePlanController extends Controller
{
    public function index(ListFlexiblePlansRequest $request, FieldFlexiblePlanningService $service): JsonResponse
    {
        return response()->json(['data' => $service->week($request->user(), $request->validated('start', today()->startOfWeek()->toDateString()),
            $request->filled('user_id') ? (int) $request->validated('user_id') : null)]);
    }

    public function store(SaveFlexiblePlanRequest $request, FieldFlexiblePlanningService $service): JsonResponse
    {
        return response()->json(['data' => $service->resource($service->save($request->user(), $request->validated()))]);
    }
}
