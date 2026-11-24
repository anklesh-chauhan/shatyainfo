<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderRegisterRequest;
use App\Models\PurchaseOrder;
use App\Services\Inventory\PurchaseOrderRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderRegister $orders) {}

    public function index(PurchaseOrderRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->orders->authorize($actor);
        $search = trim((string) $request->validated('search'));
        $page = $this->orders->visible($actor)->with([
            'supplier' => fn (BelongsTo $query) => $query->withTrashed(),
            'location' => fn (BelongsTo $query) => $query->withTrashed(),
        ])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereLike('document_number', '%'.$search.'%')->orWhereLike('notes', '%'.$search.'%')
                ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->whereLike('name', '%'.$search.'%'))
                ->orWhereHas('location', fn (Builder $location) => $location->whereLike('name', '%'.$search.'%'))))
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->validated('supplier_id'), fn (Builder $query, int $id) => $query->where('supplier_id', $id))
            ->when($request->validated('from'), fn (Builder $query, string $date) => $query->whereDate('order_date', '>=', $date))
            ->when($request->validated('until'), fn (Builder $query, string $date) => $query->whereDate('order_date', '<=', $date))
            ->orderByDesc('order_date')->orderByDesc('id')->paginate(30)
            ->through(fn (PurchaseOrder $record): array => $this->orders->summary($actor, $record));

        return response()->json($page->toArray() + ['can_create' => $actor->can('create', PurchaseOrder::class),
            'bulk_actions' => array_values(array_filter([
                $actor->can('Update:PurchaseOrder') ? 'submit' : null,
                $actor->can('deleteAny', PurchaseOrder::class) ? 'delete' : null,
            ]))])->header('Cache-Control', 'private, no-store');
    }

    public function options(PurchaseOrderRegisterRequest $request, string $type): JsonResponse
    {
        return response()->json($this->orders->options($request->user(), $type, trim((string) $request->validated('search'))));
    }

    public function show(Request $request, int $purchaseOrder): JsonResponse
    {
        return response()->json(['data' => $this->orders->detail($request->user(), $this->orders->find($request->user(), $purchaseOrder))]);
    }

    public function store(PurchaseOrderRegisterRequest $request): JsonResponse
    {
        $record = $this->orders->save($request->user(), null, $request->validated());

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    public function update(PurchaseOrderRegisterRequest $request, int $purchaseOrder): JsonResponse
    {
        $record = $this->orders->save($request->user(), $purchaseOrder, $request->validated());

        return response()->json(['data' => $this->orders->detail($request->user(), $record)]);
    }

    public function action(PurchaseOrderRegisterRequest $request, int $purchaseOrder, string $action): JsonResponse
    {
        $receipt = $this->orders->act($request->user(), [$purchaseOrder], $action, $request->validated('revision'));

        return response()->json(['data' => ['id' => $purchaseOrder, 'goods_receipt_note_id' => $receipt?->id]]);
    }

    public function bulk(PurchaseOrderRegisterRequest $request, string $action): JsonResponse
    {
        $ids = array_map(intval(...), $request->validated('ids'));
        $this->orders->act($request->user(), $ids, $action, bulk: true);

        return response()->json(['data' => ['count' => count($ids)]]);
    }
}
