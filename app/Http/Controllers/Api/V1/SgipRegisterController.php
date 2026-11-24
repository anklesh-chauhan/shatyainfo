<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SgipRegisterRequest;
use App\Models\SgipDistribution;
use App\Models\VisitPreference;
use App\Services\Marketing\SgipRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SgipRegisterController extends Controller
{
    public function __construct(private SgipRegister $distributions) {}

    public function index(SgipRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->distributions->authorizeRegister($actor);
        $query = $this->distributions->visible($actor);
        match ($request->validated('trash')) {
            'all' => $query->withTrashed(),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };
        $search = trim((string) $request->validated('search'));
        $page = $query->with(SgipRegister::RELATIONS)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereHas('doctor', fn (Builder $doctor) => $doctor->whereLike('name', '%'.$search.'%'))
                ->orWhereHas('user', fn (Builder $user) => $user->whereLike('name', '%'.$search.'%'))))
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('approval_status', $status))
            ->when($request->validated('date_from'), fn (Builder $query, string $date) => $query->whereDate('visit_date', '>=', $date))
            ->when($request->validated('date_to'), fn (Builder $query, string $date) => $query->whereDate('visit_date', '<=', $date))
            ->orderByDesc('visit_date')->orderByDesc('id')->paginate(30)
            ->through(fn (SgipDistribution $record) => $this->distributions->summary($actor, $record));

        return response()->json($page->toArray() + [
            'source_mode' => VisitPreference::current()->sgip_stock_source?->value,
            'can_create' => $actor->can('create', SgipDistribution::class),
            'bulk_actions' => collect(['delete' => 'deleteAny', 'restore' => 'restoreAny', 'force-delete' => 'forceDeleteAny'])
                ->filter(fn (string $ability) => $actor->can($ability, SgipDistribution::class))->keys()->all(),
        ]);
    }

    public function options(SgipRegisterRequest $request, string $type): JsonResponse
    {
        return response()->json($this->distributions->options($request->user(), $type, trim((string) $request->validated('search')), $request->validated('record_id')));
    }

    public function show(Request $request, int $sgipDistribution): JsonResponse
    {
        $actor = $request->user();

        return response()->json(['data' => $this->distributions->detail($actor, $this->distributions->find($actor, $sgipDistribution))]);
    }

    public function store(SgipRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $record = $this->distributions->save($actor, null, $request->validated());

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    public function update(SgipRegisterRequest $request, int $sgipDistribution): JsonResponse
    {
        $actor = $request->user();
        $record = $this->distributions->save($actor, $sgipDistribution, $request->validated());

        return response()->json(['data' => $this->distributions->detail($actor, $record)]);
    }

    public function action(SgipRegisterRequest $request, int $sgipDistribution, string $action): JsonResponse
    {
        $this->distributions->act($request->user(), [$sgipDistribution], $action, $request->validated('revision'));

        return response()->json(['data' => ['id' => $sgipDistribution]]);
    }

    public function bulk(SgipRegisterRequest $request, string $action): JsonResponse
    {
        $ids = array_map(intval(...), $request->validated('ids'));
        $this->distributions->act($request->user(), $ids, $action, bulk: true);

        return response()->json(['data' => ['count' => count($ids)]]);
    }
}
