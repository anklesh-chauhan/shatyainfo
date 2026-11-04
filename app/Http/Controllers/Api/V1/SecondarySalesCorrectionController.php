<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestSecondarySalesCorrectionRequest;
use App\Http\Requests\Api\V1\ReviewSecondarySalesRequest;
use App\Models\SecondarySalesCorrection;
use App\Models\SecondarySalesStatement;
use App\Services\Field\SecondarySalesCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecondarySalesCorrectionController extends Controller
{
    public function __construct(private SecondarySalesCorrectionService $corrections) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = SecondarySalesCorrection::query()->where(fn ($query) => $query->where('requested_by', $user->id)->orWhere('reviewer_id', $user->id))
            ->with(['statement:id,stockist_id,period,status', 'replacement:id,status,source_sha256'])->latest('id')->paginate(30);
        $page->setCollection($page->getCollection()->map(fn (SecondarySalesCorrection $correction) => [...$correction->toArray(), 'can_review' => $this->corrections->canReview($user, $correction)]));

        return response()->json($page);
    }

    public function store(RequestSecondarySalesCorrectionRequest $request, int $statement): JsonResponse
    {
        $original = SecondarySalesStatement::query()->where(fn ($query) => $query->where('created_by', $request->user()->id)->orWhere('reviewer_id', $request->user()->id))->findOrFail($statement);

        return response()->json(['data' => $this->corrections->request($request->user(), $original, $request->validated(), $request->file('file'))], 201);
    }

    public function review(ReviewSecondarySalesRequest $request, int $correction): JsonResponse
    {
        $record = SecondarySalesCorrection::query()->where('reviewer_id', $request->user()->id)->findOrFail($correction);
        $data = $request->validated();

        return response()->json(['data' => $this->corrections->review($request->user(), $record, $data['decision'], $data['note'], $data['physically_verified_line_ids'] ?? [])]);
    }
}
