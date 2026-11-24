<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoodsReceiptRegisterRequest;
use App\Models\GoodsReceiptNote;
use App\Services\Inventory\GoodsReceiptRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    public function __construct(private GoodsReceiptRegister $orders) {}

    public function index(GoodsReceiptRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->orders->authorize($actor);
        $search = trim((string) $request->validated('search'));
        $page = $this->orders->visible($actor)->with([
            'purchaseOrder' => fn (BelongsTo $query) => $query->withTrashed(),
            'approval', 'postedBy',
            'supplier' => fn (BelongsTo $query) => $query->withTrashed(),
            'location' => fn (BelongsTo $query) => $query->withTrashed(),
        ])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereLike('document_number', '%'.$search.'%')->orWhereLike('notes', '%'.$search.'%')
                ->orWhereHas('purchaseOrder', fn (Builder $po): Builder => $po->whereLike('document_number', '%'.$search.'%'))
                ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->whereLike('name', '%'.$search.'%'))
                ->orWhereHas('location', fn (Builder $location) => $location->whereLike('name', '%'.$search.'%'))))
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->validated('supplier_id'), fn (Builder $query, int $id) => $query->where('supplier_id', $id))
            ->when($request->validated('from'), fn (Builder $query, string $date) => $query->whereDate('receipt_date', '>=', $date))
            ->when($request->validated('until'), fn (Builder $query, string $date) => $query->whereDate('receipt_date', '<=', $date))
            ->orderByDesc('receipt_date')->orderByDesc('id')->paginate(30)
            ->through(fn (GoodsReceiptNote $record): array => $this->orders->summary($actor, $record));

        return response()->json($page->toArray() + ['can_create' => $actor->can('create', GoodsReceiptNote::class),
            'bulk_actions' => array_values(array_filter([
                $actor->can('deleteAny', GoodsReceiptNote::class) ? 'delete' : null,
            ]))])->header('Cache-Control', 'private, no-store');
    }

    public function options(GoodsReceiptRegisterRequest $request, string $type): JsonResponse
    {
        return response()->json($this->orders->options($request->user(), $type, trim((string) $request->validated('search'))));
    }

    public function purchaseOrder(Request $request, int $purchaseOrder): JsonResponse
    {
        return response()->json(['data' => $this->orders->orderData($request->user(), $purchaseOrder)]);
    }

    public function show(Request $request, int $goodsReceipt): JsonResponse
    {
        return response()->json(['data' => $this->orders->detail($request->user(), $this->orders->find($request->user(), $goodsReceipt))]);
    }

    public function store(GoodsReceiptRegisterRequest $request): JsonResponse
    {
        $record = $this->orders->save($request->user(), null, $request->validated());

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    public function update(GoodsReceiptRegisterRequest $request, int $goodsReceipt): JsonResponse
    {
        $record = $this->orders->save($request->user(), $goodsReceipt, $request->validated());

        return response()->json(['data' => $this->orders->detail($request->user(), $record)]);
    }

    public function action(GoodsReceiptRegisterRequest $request, int $goodsReceipt, string $action): JsonResponse
    {
        $this->orders->act($request->user(), [$goodsReceipt], $action, $request->validated('revision'));

        return response()->json(['data' => ['id' => $goodsReceipt]]);
    }

    public function bulk(GoodsReceiptRegisterRequest $request, string $action): JsonResponse
    {
        $ids = array_map(intval(...), $request->validated('ids'));
        $this->orders->act($request->user(), $ids, $action, bulk: true);

        return response()->json(['data' => ['count' => count($ids)]]);
    }
}
