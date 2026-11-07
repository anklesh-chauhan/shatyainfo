<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FieldCoverageRequest;
use App\Services\Field\FieldCoverageService;
use Illuminate\Http\JsonResponse;

class FieldCoverageController extends Controller
{
    public function __invoke(FieldCoverageRequest $request, FieldCoverageService $service): JsonResponse
    {
        return response()->json(['data' => $service->forMonth($request->user(), $request->user()->id, $request->validated('month', today()->format('Y-m')))]);
    }
}
