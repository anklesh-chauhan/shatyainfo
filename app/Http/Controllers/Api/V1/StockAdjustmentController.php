<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StockAdjustmentRequest;
use App\Models\InventoryAdjustment;
use App\Services\Inventory\StockAdjustmentRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAdjustmentController extends Controller
{
    public function __construct(private StockAdjustmentRegister $adjustments) {}

    public function index(StockAdjustmentRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->adjustments->authorize($actor);
        $search = trim((string) $request->validated('search'));
        $query = $this->adjustments->visible($actor)->with(['item' => fn (BelongsTo $query) => $query->withTrashed(),
            'location' => fn (BelongsTo $query) => $query->withTrashed(), 'approval', 'postedBy'])
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $match->whereLike('adjustment_number', '%'.$search.'%')->orWhereLike('remarks', '%'.$search.'%')->orWhereLike('reason', '%'.$search.'%')
                ->orWhereHas('item', fn (Builder $item): Builder => $item->withTrashed()->whereLike('item_name', '%'.$search.'%'))
                ->orWhereHas('location', fn (Builder $location): Builder => $location->withTrashed()->whereLike('name', '%'.$search.'%'))));
        foreach (['status', 'item_master_id', 'location_master_id', 'adjustment_type'] as $field) {
            $query->when($request->validated($field), fn (Builder $query, mixed $value): Builder => $query->where($field, $value));
        }
        $page = $query->latest('id')->paginate(30)->through(fn (InventoryAdjustment $record): array => $this->adjustments->summary($actor, $record));

        return response()->json($page->toArray() + ['can_create' => $actor->can('create', InventoryAdjustment::class), 'can_bulk_delete' => $actor->can('deleteAny', InventoryAdjustment::class)])->header('Cache-Control', 'private, no-store');
    }

    public function options(StockAdjustmentRequest $request, string $type): JsonResponse
    {
        return response()->json($this->adjustments->options($request->user(), $type, trim((string) $request->validated('search'))));
    }

    public function show(Request $request, int $adjustment): JsonResponse
    {
        return response()->json(['data' => $this->adjustments->detail($request->user(), $this->adjustments->find($request->user(), $adjustment))]);
    }

    public function store(StockAdjustmentRequest $request): JsonResponse
    {
        $record = $this->adjustments->save($request->user(), null, $request->validated());

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    public function update(StockAdjustmentRequest $request, int $adjustment): JsonResponse
    {
        $record = $this->adjustments->save($request->user(), $adjustment, $request->validated());

        return response()->json(['data' => $this->adjustments->detail($request->user(), $record)]);
    }

    public function action(StockAdjustmentRequest $request, int $adjustment, string $action): JsonResponse
    {
        $this->adjustments->act($request->user(), $adjustment, $action, $request->validated('revision'));

        return response()->json(['data' => ['id' => $adjustment]]);
    }

    public function bulkDelete(StockAdjustmentRequest $request): JsonResponse
    {
        $ids = array_map(intval(...), $request->validated('ids'));
        $this->adjustments->deleteMany($request->user(), $ids);

        return response()->json(['data' => ['count' => count($ids)]]);
    }
}
