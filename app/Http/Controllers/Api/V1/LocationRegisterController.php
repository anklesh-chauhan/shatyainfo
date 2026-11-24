<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LocationAddressRequest;
use App\Http\Requests\Api\V1\LocationRegisterRequest;
use App\Models\LocationMaster;
use App\Services\Inventory\LocationRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class LocationRegisterController extends Controller
{
    public function __construct(private LocationRegister $locations) {}

    public function index(LocationRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->locations->authorize($actor);
        $search = trim((string) $request->validated('search'));
        $page = $this->locations->visible($actor)->with('approval')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match->whereLike('name', '%'.$search.'%')->orWhereLike('location_code', '%'.$search.'%')))
            ->when($request->filled('active'), fn (Builder $query) => $query->where('is_active', $request->validated('active') === '1'))
            ->orderBy('name')->orderBy('id')->paginate(30)->through(fn (LocationMaster $record): array => $this->locations->summary($actor, $record));

        return $this->json($page->toArray() + ['can_create' => $actor->can('create', LocationMaster::class),
            'bulk_actions' => [...($actor->can('DeleteAny:LocationMaster') ? ['delete'] : []), ...($actor->can('Update:LocationMaster') && LocationMaster::moduleHasApprovalFlow() ? ['submit'] : [])]]);
    }

    public function options(LocationRegisterRequest $request, string $kind): JsonResponse
    {
        return $this->json($this->locations->options($request->user(), $kind, $request->validated()));
    }

    public function show(LocationRegisterRequest $request, int $location): JsonResponse
    {
        return $this->json(['data' => $this->locations->detail($request->user(), $this->locations->find($request->user(), $location))]);
    }

    public function store(LocationRegisterRequest $request): JsonResponse
    {
        $record = $this->locations->save($request->user(), null, $request->validated());

        return $this->json(['data' => ['id' => $record->id]], 201);
    }

    public function update(LocationRegisterRequest $request, int $location): JsonResponse
    {
        return $this->json(['data' => $this->locations->detail($request->user(), $this->locations->save($request->user(), $location, $request->validated()))]);
    }

    public function action(LocationRegisterRequest $request, int $location, string $action): JsonResponse
    {
        $this->locations->act($request->user(), [$location], $action, $request->validated('revision'));

        return $this->json(['data' => ['changed' => true]]);
    }

    public function bulk(LocationRegisterRequest $request, string $action): JsonResponse
    {
        $this->locations->act($request->user(), $request->validated('ids'), $action, bulk: true);

        return $this->json(['data' => ['changed' => true]]);
    }

    public function related(LocationRegisterRequest $request, int $location, string $relation): JsonResponse
    {
        return $this->json($this->locations->related($request->user(), $location, $relation, $request->validated()));
    }

    public function address(LocationAddressRequest $request, int $location, ?int $address = null): JsonResponse
    {
        $this->locations->changeAddress($request->user(), $location, $address, $request->isMethod('DELETE') ? null : $request->validated(), $request->validated('revision'));

        return $this->json(['data' => ['changed' => true]]);
    }

    public function image(LocationRegisterRequest $request, int $location): Response
    {
        $record = $this->locations->find($request->user(), $location);
        abort_unless($record->image && Storage::exists($record->image), 404);
        $bytes = Storage::get($record->image);
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true), 404);

        return response($bytes, 200, ['Content-Type' => $info['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
