<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSalesTargetRequest;
use App\Http\Requests\Api\V1\StoreTerritorySalesAssignmentRequest;
use App\Http\Requests\Api\V1\TerritorySalesAssignmentRegisterRequest;
use App\Models\TerritorySalesAssignment;
use App\Models\User;
use App\Services\Field\SalesPerformanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesPerformanceController extends Controller
{
    public function index(Request $request, SalesPerformanceService $service): JsonResponse
    {
        $actor = $request->user();
        $page = $service->visibleTargets($actor)->latest('starts_on')->orderBy('territory_id')->orderBy('id')->paginate(100);
        $canManage = $actor->can('Manage:SalesTargets');
        $territoryIds = $canManage ? $service->territories($actor)->pluck('id')->flip() : collect();
        $page->through(fn ($target): array => [
            ...$service->targetData($target),
            'can_revise' => $territoryIds->has($target->territory_id) && $target->starts_on->gt(today()),
        ]);

        return response()->json($page->toArray() + [
            'register' => ['can_create' => $canManage && $territoryIds->isNotEmpty(), 'today' => today()->toDateString()],
        ]);
    }

    public function territories(Request $request, SalesPerformanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->territories($request->user())->get(['id', 'name'])]);
    }

    public function store(StoreSalesTargetRequest $request, SalesPerformanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->targetData($service->createTarget($request->user(), $request->validated()))], 201);
    }

    public function update(StoreSalesTargetRequest $request, int $target, SalesPerformanceService $service): JsonResponse
    {
        $actor = $request->user();
        $record = $service->visibleTargets($actor)->findOrFail($target);

        return response()->json(['data' => $service->targetData($service->reviseTarget($actor, $record, $request->validated()))]);
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

    public function assignments(TerritorySalesAssignmentRegisterRequest $request, SalesPerformanceService $service): JsonResponse
    {
        $territories = $service->territories($request->user());
        $search = $request->validated('search');
        if ($search !== null && $search !== '') {
            $territories->whereLike('name', '%'.$search.'%');
        }

        $page = TerritorySalesAssignment::query()->with(['territory:id,name', 'user:id,name'])
            ->whereIn('territory_id', $territories->reorder()->select('id'))
            ->latest('effective_from')->orderByDesc('id')->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (TerritorySalesAssignment $assignment): array => [
                'id' => $assignment->id,
                'territory' => $assignment->territory?->name,
                'representative' => $assignment->user?->name,
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'reason' => $assignment->reason,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'register' => [
                'can_assign' => $request->user()->can('Manage:SalesTargets'),
                'today' => today()->toDateString(),
            ],
        ]);
    }

    public function assignmentTerritories(TerritorySalesAssignmentRegisterRequest $request, SalesPerformanceService $service): JsonResponse
    {
        abort_unless($request->user()->can('Manage:SalesTargets'), 403);

        return $this->assignmentOptions($request, $service->territories($request->user())->reorder());
    }

    public function assignmentRepresentatives(TerritorySalesAssignmentRegisterRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('Manage:SalesTargets'), 403);

        return $this->assignmentOptions($request, User::query());
    }

    private function assignmentOptions(TerritorySalesAssignmentRegisterRequest $request, Builder $query): JsonResponse
    {
        $search = $request->validated('search');
        $page = $query->when($search !== null && $search !== '', fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(30, ['id', 'name']);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($option): array => ['id' => $option->id, 'label' => $option->name]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }
}
