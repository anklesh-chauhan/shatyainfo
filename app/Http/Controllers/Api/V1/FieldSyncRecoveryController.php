<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestFieldSyncRecoveryRequest;
use App\Http\Requests\Api\V1\ReviewFieldSyncRecoveryRequest;
use App\Models\FieldSyncRecovery;
use App\Models\User;
use App\Services\Field\FieldConflictResolutionService;
use App\Services\Field\FieldPlanConflictService;
use App\Services\Field\FieldSyncRecoveryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FieldSyncRecoveryController extends Controller
{
    public function index(Request $request, FieldSyncRecoveryService $service): JsonResponse
    {
        $user = $request->user();
        $query = FieldSyncRecovery::query()->where(function (Builder $query) use ($user): void {
            $query->where('user_id', $user->id);
            if ($user->can('Review:FieldSyncRecovery')) {
                $query->orWhere('reviewer_id', $user->id);
            }
        });
        if ($request->filled('operation_id')) {
            abort_unless(Str::isUuid($request->query('operation_id')), 422, 'Choose a valid operation reference.');
            $query->where('operation_id', $request->query('operation_id'));
        }
        $page = $query->with(['user:id,name', 'reviewer:id,name'])->latest('id')->paginate(100);
        $page->setCollection($page->getCollection()->map(fn ($record) => [...$record->toArray(),
            'review_token' => $record->reviewToken(),
            'can_request_plan_resolution' => (int) $record->user_id === (int) $user->id && app(FieldPlanConflictService::class)->eligible($record),
            'can_review' => $record->status === 'pending_review' && (int) $record->reviewer_id === (int) $user->id && $user->can('Review:FieldSyncRecovery'),
            'can_request_resolution' => (int) $record->user_id === (int) $user->id && app(FieldConflictResolutionService::class)->eligible($record),
            'can_request_replacement' => (int) $record->user_id === (int) $user->id && app(FieldConflictResolutionService::class)->eligible($record),
            'can_request_review' => ($record->resolution_mode === 'retry_original' || $record->recovered_at) && (int) $record->user_id === (int) $user->id && ($record->status === 'failed' || (in_array($record->status, ['approved', 'recovered'], true) && $record->expires_at?->isPast())) && $service->needsWindowException($record)]));

        return response()->json($page);
    }

    public function reviewers(Request $request): JsonResponse
    {
        return response()->json(['data' => User::query()->where('id', '!=', $request->user()->id)->orderBy('name')->get()
            ->filter(fn ($user) => $user->can('Review:FieldSyncRecovery'))->map->only(['id', 'name'])->values()]);
    }

    public function requestReview(RequestFieldSyncRecoveryRequest $request, string $operation, FieldSyncRecoveryService $service): JsonResponse
    {
        $record = FieldSyncRecovery::query()->where('user_id', $request->user()->id)->where('operation_id', $operation)->firstOrFail();

        return response()->json(['data' => $service->requestReview($request->user(), $record, $request->validated())]);
    }

    public function review(ReviewFieldSyncRecoveryRequest $request, int $recovery, FieldSyncRecoveryService $service): JsonResponse
    {
        return response()->json(['data' => $service->review($request->user(), FieldSyncRecovery::query()->findOrFail($recovery), $request->validated())]);
    }
}
