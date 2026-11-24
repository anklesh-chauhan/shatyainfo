<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SampleIssueRegisterRequest;
use App\Models\SampleIssue;
use App\Services\Inventory\SampleIssueRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SampleIssueController extends Controller
{
    public function __construct(private SampleIssueRegister $issues) {}

    public function index(SampleIssueRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->issues->authorizeRegister($actor);
        $query = $this->issues->visible($actor);
        match ($request->validated('trash')) {
            'all' => $query->withTrashed(),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };
        $search = trim((string) $request->validated('search'));
        $page = $query->with(SampleIssueRegister::RELATIONS)

            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereLike('document_number', '%'.$search.'%')
                ->orWhereLike('notes', '%'.$search.'%')
                ->orWhereHas('sampleRequest.employee', fn (Builder $employee) => $employee->whereLike('first_name', '%'.$search.'%')->orWhereLike('last_name', '%'.$search.'%'))
                ->orWhereHas('toLocation', fn (Builder $location) => $location->whereLike('name', '%'.$search.'%'))))
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('issue_date')->orderByDesc('id')->paginate(30)
            ->through(fn (SampleIssue $record) => $this->issues->summary($actor, $record));

        return response()->json($page->toArray() + [
            'can_create' => $actor->can('create', SampleIssue::class),
            'bulk_actions' => collect(['delete' => 'deleteAny', 'restore' => 'restoreAny', 'force-delete' => 'forceDeleteAny'])
                ->filter(fn (string $ability) => $actor->can($ability, SampleIssue::class))->keys()->all(),
        ]);
    }

    public function options(SampleIssueRegisterRequest $request, string $type): JsonResponse
    {
        return response()->json($this->issues->options($request->user(), $type, trim((string) $request->validated('search'))));
    }

    public function requestOptions(Request $request, int $sampleRequest): JsonResponse
    {
        return response()->json(['data' => $this->issues->request($request->user(), $sampleRequest)]);
    }

    public function show(Request $request, int $sampleIssue): JsonResponse
    {
        $actor = $request->user();

        return response()->json(['data' => $this->issues->detail($actor, $this->issues->find($actor, $sampleIssue))]);
    }

    public function store(SampleIssueRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $record = $this->issues->save($actor, null, $request->validated());

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    public function update(SampleIssueRegisterRequest $request, int $sampleIssue): JsonResponse
    {
        $actor = $request->user();
        $record = $this->issues->save($actor, $sampleIssue, $request->validated());

        return response()->json(['data' => $this->issues->detail($actor, $record)]);
    }

    public function action(SampleIssueRegisterRequest $request, int $sampleIssue, string $action): JsonResponse
    {
        $this->issues->act($request->user(), [$sampleIssue], $action, $request->validated('revision'));

        return response()->json(['data' => ['id' => $sampleIssue]]);
    }

    public function bulk(SampleIssueRegisterRequest $request, string $action): JsonResponse
    {
        $ids = array_map(intval(...), $request->validated('ids'));
        $this->issues->act($request->user(), $ids, $action, bulk: true);

        return response()->json(['data' => ['count' => count($ids)]]);
    }
}
