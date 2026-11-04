<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSalesTargetRequest;
use App\Http\Requests\Api\V1\StoreTerritorySalesAssignmentRequest;
use App\Services\Field\SalesPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesPerformanceController extends Controller
{
    public function index(Request $request, SalesPerformanceService $service): JsonResponse
    {
        $page = $service->visibleTargets($request->user())->latest('starts_on')->orderBy('territory_id')->paginate(100);
        $page->setCollection($page->getCollection()->map(fn ($target) => $service->targetData($target)));

        return response()->json($page);
    }

    public function territories(Request $request, SalesPerformanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->territories($request->user())->get(['id', 'name'])]);
    }

    public function store(StoreSalesTargetRequest $request, SalesPerformanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->targetData($service->createTarget($request->user(), $request->validated()))], 201);
    }

    public function achievement(Request $request, int $target, SalesPerformanceService $service): JsonResponse
    {
        $record = $service->visibleTargets($request->user())->findOrFail($target);

        return response()->json(['data' => $service->dashboard($request->user(), $record)]);
    }

    public function assign(StoreTerritorySalesAssignmentRequest $request, SalesPerformanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->assign($request->user(), $request->validated())], 201);
    }
}
