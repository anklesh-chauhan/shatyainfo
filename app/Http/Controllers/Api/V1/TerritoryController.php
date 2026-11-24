<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteTerritoriesRequest;
use App\Http\Requests\Api\V1\SaveTerritoryPatchRequest;
use App\Http\Requests\Api\V1\SaveTerritoryRequest;
use App\Http\Requests\Api\V1\TerritoryRegisterRequest;
use App\Http\Resources\Api\V1\TerritoryResource;
use App\Models\Patch;
use App\Models\User;
use App\Services\Field\FieldTerritoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TerritoryController extends Controller
{
    public function __construct(private FieldTerritoryService $territories) {}

    public function index(TerritoryRegisterRequest $request): AnonymousResourceCollection
    {
        return TerritoryResource::collection($this->territories->list($request->user(), $request->validated()))
            ->additional(['register' => $this->territories->capabilities($request->user())]);
    }

    public function show(Request $request, int $territory): TerritoryResource
    {
        return new TerritoryResource($this->territories->show($request->user(), $territory));
    }

    public function store(SaveTerritoryRequest $request): TerritoryResource
    {
        return new TerritoryResource($this->territories->save($request->user(), $request->validated()));
    }

    public function update(SaveTerritoryRequest $request, int $territory): TerritoryResource
    {
        return new TerritoryResource($this->territories->save($request->user(), $request->validated(), $territory));
    }

    public function delete(DeleteTerritoriesRequest $request): JsonResponse
    {
        $count = $this->territories->delete($request->user(), array_map('intval', $request->validated('ids')));

        return response()->json(['data' => ['updated' => $count]]);
    }

    public function options(TerritoryRegisterRequest $request, string $kind): JsonResponse
    {
        $territory = $request->validated('territory_id');
        $page = $this->territories->options($request->user(), $kind, trim($request->validated('search') ?? ''), $territory === null ? null : (int) $territory);

        return response()->json(['data' => $page->through(TerritoryResource::option(...))->items(), 'meta' => ['last_page' => $page->lastPage()]]);
    }

    public function patches(TerritoryRegisterRequest $request, int $territory): JsonResponse
    {
        $page = $this->territories->patches($request->user(), $territory, $request->validated());

        return response()->json([
            'data' => $page->through(fn (Patch $patch): array => $this->patchPayload($request->user(), $patch))->items(),
            'meta' => ['last_page' => $page->lastPage()],
        ]);
    }

    public function updatePatch(SaveTerritoryPatchRequest $request, int $territory, int $patch): JsonResponse
    {
        $record = $this->territories->savePatch($request->user(), $territory, $patch, $request->validated());

        return response()->json(['data' => $this->patchPayload($request->user(), $record)]);
    }

    private function patchPayload(User $actor, Patch $patch): array
    {
        return [
            'id' => $patch->id,
            'name' => $patch->name,
            'code' => $patch->code,
            'description' => $patch->description,
            'color' => $patch->color,
            'city_pin_code' => $patch->cityPinCode ? TerritoryResource::option($patch->cityPinCode) : null,
            'can_edit' => $actor->can('update', $patch) && ! $patch->territory->trashed()
                && ! $this->territories->locked($actor, $patch->territory) && ! $this->territories->locked($actor, $patch),
        ];
    }
}
