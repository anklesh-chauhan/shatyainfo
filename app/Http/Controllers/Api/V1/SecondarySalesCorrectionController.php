<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestSecondarySalesCorrectionRequest;
use App\Http\Requests\Api\V1\ReviewSecondarySalesRequest;
use App\Http\Requests\Api\V1\SecondarySalesRegisterRequest;
use App\Models\SecondarySalesCorrection;
use App\Models\SecondarySalesStatement;
use App\Models\User;
use App\Services\Field\SecondarySalesCorrectionService;
use App\Services\Field\SecondarySalesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecondarySalesCorrectionController extends Controller
{
    public function __construct(private SecondarySalesCorrectionService $corrections) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeRegister($user);
        $page = $this->visible($user)->with([
            'statement:id,stockist_id,period,status,created_by',
            'statement.stockist:id,name',
            'replacement:id,status,source_sha256',
        ])->latest('id')->paginate(30);
        $page->setCollection($page->getCollection()->map(fn (SecondarySalesCorrection $correction) => [...$correction->toArray(), 'can_review' => $this->corrections->canReview($user, $correction)]));

        return response()->json($page);
    }

    public function show(Request $request, int $correction): JsonResponse
    {
        $record = $this->owned($request, $correction)->load([
            'statement:id,stockist_id,period,status,created_by',
            'statement.stockist:id,name',
            'replacement:id,status,source_kind,balance_rules,source_path',
            'requester:id,name',
            'reviewer:id,name',
        ]);
        $replacement = $record->replacement;
        if ($replacement !== null) {
            $replacement->setAttribute('source_extension', SecondarySalesService::sourceExtension($replacement));
            $replacement->setAttribute('adjustments_count', $replacement->lines()->where('transaction_type', '!=', 'sale')->count());
            $replacement->makeHidden('source_path');
        }

        return response()->json(['data' => [
            ...$record->toArray(),
            'can_review' => $this->corrections->canReview($request->user(), $record),
        ]]);
    }

    public function evidence(SecondarySalesRegisterRequest $request, int $correction, string $section): JsonResponse
    {
        return response()->json(SecondarySalesService::evidence($this->replacement($request, $correction), $section));
    }

    public function source(Request $request, int $correction): StreamedResponse
    {
        return SecondarySalesService::downloadSource($this->replacement($request, $correction));
    }

    private function replacement(Request $request, int $id): SecondarySalesStatement
    {
        return $this->owned($request, $id)->replacement()->firstOrFail();
    }

    private function owned(Request $request, int $id): SecondarySalesCorrection
    {
        $user = $request->user();
        $correction = $this->visible($user)->findOrFail($id);
        $this->authorizeRegister($user);

        return $correction;
    }

    private function visible(User $user): Builder
    {
        return SecondarySalesCorrection::query()->where(fn (Builder $query): Builder => $query
            ->where('requested_by', $user->id)->orWhere('reviewer_id', $user->id));
    }

    private function authorizeRegister(User $user): void
    {
        abort_unless($user->can('viewAny', SecondarySalesCorrection::class)
            || $user->can('Create:SecondarySales') || $user->can('Review:SecondarySales'), 403);
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
