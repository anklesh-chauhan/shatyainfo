<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpsertVisitSgipRequest;
use App\Http\Resources\Api\V1\SgipDistributionResource;
use App\Services\Field\FieldSgipService;
use App\Services\Field\FieldVisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitSgipController extends Controller
{
    public function __construct(
        private FieldVisitService $visitService,
        private FieldSgipService $sgipService,
    ) {}

    public function show(Request $request, int $visit): JsonResponse
    {
        $record = $this->visitService->findOwned($request->user(), $visit);
        $distribution = $this->sgipService->show($request->user(), $record);

        return response()->json([
            'data' => $distribution ? new SgipDistributionResource($distribution) : null,
        ]);
    }

    public function upsert(UpsertVisitSgipRequest $request, int $visit): SgipDistributionResource
    {
        $record = $this->visitService->findOwned($request->user(), $visit);

        return new SgipDistributionResource(
            $this->sgipService->upsert($request->user(), $record, $request->validated()),
        );
    }

    public function submit(Request $request, int $visit): SgipDistributionResource
    {
        $record = $this->visitService->findOwned($request->user(), $visit);

        return new SgipDistributionResource(
            $this->sgipService->submit($request->user(), $record),
        );
    }
}
