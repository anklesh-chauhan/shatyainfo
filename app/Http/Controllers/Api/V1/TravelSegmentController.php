<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListTravelSegmentsRequest;
use App\Http\Requests\Api\V1\SaveTravelSegmentRequest;
use App\Http\Requests\Api\V1\TravelSegmentLifecycleRequest;
use App\Services\Travel\TravelSegmentRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TravelSegmentController extends Controller
{
    public function index(ListTravelSegmentsRequest $request, TravelSegmentRegister $register): JsonResponse
    {
        return response()->json($register->index($request->user(), $request->validated()));
    }

    public function show(Request $request, int $segment, TravelSegmentRegister $register): JsonResponse
    {
        return response()->json(['data' => $register->detail($request->user(), $segment)]);
    }

    public function options(ListTravelSegmentsRequest $request, TravelSegmentRegister $register): JsonResponse
    {
        return response()->json($register->options($request->user(), $request->validated()));
    }

    public function store(SaveTravelSegmentRequest $request, TravelSegmentRegister $register): JsonResponse
    {
        return response()->json(['data' => $register->save($request->user(), $request->validated())], 201);
    }

    public function update(SaveTravelSegmentRequest $request, int $segment, TravelSegmentRegister $register): JsonResponse
    {
        return response()->json(['data' => $register->save($request->user(), $request->validated(), $segment)]);
    }

    public function lifecycle(TravelSegmentLifecycleRequest $request, TravelSegmentRegister $register): JsonResponse
    {
        return response()->json(['data' => ['updated' => $register->lifecycle(
            $request->user(), $request->validated('action'), array_map('intval', $request->validated('ids')),
        )]]);
    }
}
