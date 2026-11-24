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
        $actor = $request->user();
        $report = $service->forMonth($actor, (int) $request->validated('user_id', $actor->id),
            $request->validated('month', today()->format('Y-m')),
            $request->has('hq_id') ? $request->integer('hq_id') : null);
        $team = $request->boolean('include_team') ? $service->teamReview($actor) : null;

        return response()->json(['data' => $report, ...($team === null ? [] : ['team_review' => $team])]);
    }
}
