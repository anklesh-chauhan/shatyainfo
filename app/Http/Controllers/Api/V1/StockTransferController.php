<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StockTransferRequest;
use App\Models\InventoryTransfer;
use App\Services\Inventory\StockTransferRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(private StockTransferRegister $transfers) {}

    public function index(StockTransferRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->transfers->authorize($actor);
        $search = trim((string) $request->validated('search'));
        $query = $this->transfers->visible($actor)->with(['item' => fn (BelongsTo $query) => $query->withTrashed(),
            'fromLocation' => fn (BelongsTo $query) => $query->withTrashed(), 'toLocation' => fn (BelongsTo $query) => $query->withTrashed(), 'approval', 'postedBy'])
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $match->whereLike('transfer_number', '%'.$search.'%')->orWhereLike('remarks', '%'.$search.'%')
                ->orWhereHas('item', fn (Builder $item): Builder => $item->withTrashed()->whereLike('item_name', '%'.$search.'%'))
                ->orWhereHas('fromLocation', fn (Builder $location): Builder => $location->withTrashed()->whereLike('name', '%'.$search.'%'))
                ->orWhereHas('toLocation', fn (Builder $location): Builder => $location->withTrashed()->whereLike('name', '%'.$search.'%'))));
        foreach (['status', 'item_master_id', 'from_location_master_id', 'to_location_master_id'] as $field) {
            $query->when($request->validated($field), fn (Builder $query, mixed $value): Builder => $query->where($field, $value));
        }
        $page = $query->latest('id')->paginate(30)->through(fn (InventoryTransfer $record): array => $this->transfers->summary($actor, $record));

        return response()->json($page->toArray() + ['can_create' => $actor->can('create', InventoryTransfer::class)])->header('Cache-Control', 'private, no-store');
    }

    public function options(StockTransferRequest $request, string $type): JsonResponse
    {
        return response()->json($this->transfers->options($request->user(), $type, trim((string) $request->validated('search'))));
    }

    public function show(Request $request, int $transfer): JsonResponse
    {
        return response()->json(['data' => $this->transfers->detail($request->user(), $this->transfers->find($request->user(), $transfer))]);
    }

    public function store(StockTransferRequest $request): JsonResponse
    {
        $record = $this->transfers->save($request->user(), null, $request->validated());

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    public function update(StockTransferRequest $request, int $transfer): JsonResponse
    {
        $record = $this->transfers->save($request->user(), $transfer, $request->validated());

        return response()->json(['data' => $this->transfers->detail($request->user(), $record)]);
    }

    public function action(StockTransferRequest $request, int $transfer, string $action): JsonResponse
    {
        $this->transfers->act($request->user(), $transfer, $action, $request->validated('revision'));

        return response()->json(['data' => ['id' => $transfer]]);
    }
}
