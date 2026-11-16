<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpsertVisitSgipRequest;
use App\Http\Resources\Api\V1\SgipDistributionResource;
use App\Models\SgipDistribution;
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

    public function register(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', SgipDistribution::class), 403);

        $rows = SgipDistribution::query()->where('user_id', $user->id)
            ->whereHas('visit', fn ($visits) => $visits->where('employee_id', $user->id))
            ->with('accountMaster:id,name')
            ->orderByDesc('visit_date')->orderByDesc('id')->paginate(25)
            ->through(fn (SgipDistribution $row): array => [
                'id' => $row->id, 'visit_id' => $row->visit_id,
                'visit_date' => $row->visit_date?->toDateString(),
                'customer' => $row->accountMaster?->name,
                'approval_status' => $row->approval_status,
            ]);

        return response()->json($rows);
    }

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
