<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ItemRegisterRequest;
use App\Models\ItemMaster;
use App\Services\Inventory\ItemRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemRegisterController extends Controller
{
    public function __construct(private ItemRegister $items) {}

    public function index(ItemRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->items->authorizeRegister($actor);
        $query = $this->items->visible($actor)->whereNull('parent_id')->with('approval');
        $search = trim((string) $request->validated('search'));
        $page = $query->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
            ->whereLike('item_name', '%'.$search.'%')->orWhereLike('item_code', '%'.$search.'%')->orWhereLike('sku', '%'.$search.'%')))
            ->when($request->validated('type'), fn (Builder $query, string $type) => $query->where('item_type', $type))
            ->when($request->boolean('expired'), fn (Builder $query) => $query->whereDate('expiry_date', '<', today()))
            ->orderBy('item_name')->orderBy('id')->paginate(30)->through(fn (ItemMaster $item): array => $this->items->summary($actor, $item));

        return response()->json($page->toArray() + ['can_create' => $actor->can('create', ItemMaster::class),
            'bulk_actions' => [...($actor->can('DeleteAny:ItemMaster') ? ['delete'] : []), ...($actor->can('Update:ItemMaster') && ItemMaster::moduleHasApprovalFlow() ? ['submit'] : [])]]);
    }

    public function options(ItemRegisterRequest $request, string $type): JsonResponse
    {
        return response()->json($this->items->options($request->user(), $type, trim((string) $request->validated('search'))));
    }

    public function show(Request $request, int $item): JsonResponse
    {
        return response()->json(['data' => $this->items->detail($request->user(), $this->items->find($request->user(), $item))]);
    }

    public function store(ItemRegisterRequest $request, ?int $parent = null): JsonResponse
    {
        $item = $this->items->save($request->user(), null, $request->validated(), $parent);

        return response()->json(['data' => ['id' => $item->id]], 201);
    }

    public function update(ItemRegisterRequest $request, int $item): JsonResponse
    {
        return response()->json(['data' => $this->items->detail($request->user(), $this->items->save($request->user(), $item, $request->validated()))]);
    }

    public function action(ItemRegisterRequest $request, int $item, string $action): JsonResponse
    {
        $this->items->act($request->user(), [$item], $action, $request->validated('revision'));

        return response()->json(['data' => ['changed' => true]]);
    }

    public function bulk(ItemRegisterRequest $request, string $action): JsonResponse
    {
        $this->items->act($request->user(), $request->validated('ids'), $action, bulk: true);

        return response()->json(['data' => ['changed' => true]]);
    }

    public function related(ItemRegisterRequest $request, int $item, string $relation): JsonResponse
    {
        return response()->json($this->items->related($request->user(), $item, $relation, trim((string) $request->validated('search')), $request->validated('trash', 'active')));
    }

    public function relate(ItemRegisterRequest $request, int $item, string $relation, string $action): JsonResponse
    {
        if ($relation === 'variants' && $action !== 'attach') {
            $this->items->act($request->user(), $request->validated('ids'), $action, bulk: true, parentId: $item, parentRevision: $request->validated('revision'));
        } else {
            $this->items->relate($request->user(), $item, $relation, $action, $request->validated('ids'), $request->validated('revision'), $request->validated());
        }

        return response()->json(['data' => ['changed' => true]]);
    }
}
