<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteFollowUpsRequest;
use App\Http\Requests\Api\V1\StoreFollowUpRequest;
use App\Http\Requests\Api\V1\UpdateFollowUpRequest;
use App\Http\Resources\Api\V1\FollowUpResource;
use App\Services\Field\FieldFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FollowUpController extends Controller
{
    public function __construct(private FieldFollowUpService $followUps) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->query('search');
        $leadId = filter_var($request->query('lead_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return FollowUpResource::collection(
            $this->followUps->list(
                $request->user(),
                is_string($search) && $search !== '' ? $search : null,
                $leadId === false ? null : $leadId,
                (int) $request->query('per_page', 50),
            ),
        );
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->followUps->options($request->user())]);
    }

    public function show(Request $request, int $followUp): FollowUpResource
    {
        return new FollowUpResource($this->followUps->findVisible($request->user(), $followUp));
    }

    public function store(StoreFollowUpRequest $request): FollowUpResource
    {
        return new FollowUpResource(
            $this->followUps->store($request->user(), $request->validated()),
        );
    }

    public function update(UpdateFollowUpRequest $request, int $followUp): FollowUpResource
    {
        $record = $this->followUps->findVisible($request->user(), $followUp);

        return new FollowUpResource(
            $this->followUps->update($request->user(), $record, $request->validated()),
        );
    }

    public function destroy(Request $request, int $followUp): JsonResponse
    {
        return response()->json(['data' => ['deleted' => $this->followUps->delete($request->user(), [$followUp])]]);
    }

    public function bulkDelete(DeleteFollowUpsRequest $request): JsonResponse
    {
        $ids = array_map('intval', $request->validated('ids'));

        return response()->json(['data' => ['deleted' => $this->followUps->delete($request->user(), $ids)]]);
    }
}
