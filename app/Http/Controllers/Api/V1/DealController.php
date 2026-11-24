<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteDealsRequest;
use App\Http\Requests\Api\V1\StoreDealRequest;
use App\Http\Requests\Api\V1\UpdateDealRequest;
use App\Http\Resources\Api\V1\DealResource;
use App\Services\Field\FieldDealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DealController extends Controller
{
    public function __construct(private FieldDealService $deals) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 50);

        return DealResource::collection(
            $this->deals->list(
                $request->user(),
                is_string($search) && $search !== '' ? $search : null,
                $perPage,
            ),
        );
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->deals->options($request->user())]);
    }

    public function show(Request $request, int $deal): DealResource
    {
        return new DealResource($this->deals->findVisible($request->user(), $deal));
    }

    public function store(StoreDealRequest $request): DealResource
    {
        return new DealResource(
            $this->deals->store($request->user(), $request->validated()),
        );
    }

    public function update(UpdateDealRequest $request, int $deal): DealResource
    {
        $record = $this->deals->findVisible($request->user(), $deal);

        return new DealResource(
            $this->deals->update($request->user(), $record, $request->validated()),
        );
    }

    public function destroy(Request $request, int $deal): JsonResponse
    {
        return response()->json(['data' => ['deleted' => $this->deals->delete($request->user(), [$deal])]]);
    }

    public function bulkDelete(DeleteDealsRequest $request): JsonResponse
    {
        $ids = array_map(intval(...), $request->validated('ids'));

        return response()->json(['data' => ['deleted' => $this->deals->delete($request->user(), $ids)]]);
    }
}
