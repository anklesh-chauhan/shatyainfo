<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SampleRequestRegisterRequest;
use App\Models\SampleRequest;
use App\Services\Inventory\SampleRequestRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SampleRequestController extends Controller
{
    public function __construct(private SampleRequestRegister $requests) {}

    public function index(SampleRequestRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->requests->authorizeRegister($actor);
        $query = $this->requests->visible($actor);
        match ($request->validated('trash')) {
            'all' => $query->withTrashed(),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };
        $search = trim((string) $request->validated('search'));
        $page = $query->with(SampleRequestRegister::RELATIONS)
            ->withExists(['issues as has_issues' => fn (Builder $issues) => $issues->withTrashed()])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereLike('document_number', '%'.$search.'%')
                ->orWhereLike('purpose', '%'.$search.'%')
                ->orWhereHas('employee', fn (Builder $employee) => $employee->whereLike('first_name', '%'.$search.'%')->orWhereLike('last_name', '%'.$search.'%'))
                ->orWhereHas('destinationLocation', fn (Builder $location) => $location->whereLike('name', '%'.$search.'%'))))
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('request_date')->orderByDesc('id')->paginate(30)
            ->through(fn (SampleRequest $record) => $this->requests->summary($actor, $record));

        return response()->json($page->toArray() + [
            'can_create' => $actor->can('create', SampleRequest::class),
            'bulk_actions' => collect(['delete' => 'deleteAny', 'restore' => 'restoreAny', 'force-delete' => 'forceDeleteAny'])
                ->filter(fn (string $ability) => $actor->can($ability, SampleRequest::class))->keys()->all(),
        ]);
    }

    public function options(SampleRequestRegisterRequest $request, string $type): JsonResponse
    {
        return response()->json($this->requests->options($request->user(), $type, trim((string) $request->validated('search'))));
    }

    public function show(Request $request, int $sampleRequest): JsonResponse
    {
        $actor = $request->user();

        return response()->json(['data' => $this->requests->detail($actor, $this->requests->find($actor, $sampleRequest))]);
    }

    public function store(SampleRequestRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $record = $this->requests->save($actor, null, $request->validated());

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    public function update(SampleRequestRegisterRequest $request, int $sampleRequest): JsonResponse
    {
        $actor = $request->user();
        $record = $this->requests->save($actor, $sampleRequest, $request->validated());

        return response()->json(['data' => $this->requests->detail($actor, $record)]);
    }

    public function action(SampleRequestRegisterRequest $request, int $sampleRequest, string $action): JsonResponse
    {
        $this->requests->act($request->user(), [$sampleRequest], $action, $request->validated('revision'), $request->validated('approved', []));

        return response()->json(['data' => ['id' => $sampleRequest]]);
    }

    public function bulk(SampleRequestRegisterRequest $request, string $action): JsonResponse
    {
        $ids = array_map(intval(...), $request->validated('ids'));
        $this->requests->act($request->user(), $ids, $action, bulk: true);

        return response()->json(['data' => ['count' => count($ids)]]);
    }
}
