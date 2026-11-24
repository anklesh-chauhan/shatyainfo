<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PositionRegisterRequest;
use App\Models\Position;
use App\Services\HR\PositionRegister;
use Illuminate\Http\JsonResponse;

class PositionRegisterController extends Controller
{
    public function __construct(private PositionRegister $positions) {}

    public function index(PositionRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $page = $this->positions->listQuery($actor, $request->validated())->orderBy($request->validated('sort', 'name'), $request->validated('direction', 'asc'))->orderBy('id')->paginate(30)
            ->through(fn (Position $record): array => $this->positions->summary($actor, $record));

        return $this->json($page->toArray() + ['can_create' => $actor->can('Create:Position'), 'bulk_actions' => array_values(array_filter(['submit', 'delete'], fn (string $action): bool => $actor->can(($action === 'delete' ? 'DeleteAny' : 'Update').':Position') && ($action !== 'submit' || Position::moduleHasApprovalFlow())))]);
    }

    public function form(PositionRegisterRequest $request): JsonResponse
    {
        $this->positions->authorize($request->user());

        return $this->json(['fields' => $this->positions->fields(), 'can_assign_employees' => $request->user()->can(['ViewAny:Employee', 'View:Employee', 'Update:Employee'])]);
    }

    public function options(PositionRegisterRequest $request, string $kind): JsonResponse
    {
        return $this->json($this->positions->options($request->user(), $kind, $request->validated()));
    }

    public function show(PositionRegisterRequest $request, int $position): JsonResponse
    {
        return $this->json(['data' => $this->positions->detail($request->user(), $this->positions->find($request->user(), $position))]);
    }

    public function store(PositionRegisterRequest $request): JsonResponse
    {
        return $this->json(['data' => ['id' => $this->positions->save($request->user(), null, $request->validated())->id]], 201);
    }

    public function update(PositionRegisterRequest $request, int $position): JsonResponse
    {
        return $this->json(['data' => $this->positions->detail($request->user(), $this->positions->save($request->user(), $position, $request->validated()))]);
    }

    public function action(PositionRegisterRequest $request, int $position, string $action): JsonResponse
    {
        $this->positions->act($request->user(), [$position], $action, $request->validated('revision'));

        return $this->json(['data' => ['changed' => true]]);
    }

    public function bulk(PositionRegisterRequest $request, string $action): JsonResponse
    {
        $this->positions->act($request->user(), $request->validated('ids'), $action, bulk: true);

        return $this->json(['data' => ['changed' => true]]);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }
}
