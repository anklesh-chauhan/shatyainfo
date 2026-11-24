<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeliveryChallanRegisterRequest;
use App\Models\DeliveryChallan;
use App\Services\Inventory\DeliveryChallanRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryChallanController extends Controller
{
    public function __construct(private DeliveryChallanRegister $challans) {}

    public function index(DeliveryChallanRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->challans->authorize($actor);
        $search = trim((string) $request->validated('search'));
        $page = $this->challans->visible($actor)->with([
            'salesInvoice' => fn (BelongsTo $query) => $query->withTrashed(),
            'approval', 'postedBy',
            'customer' => fn (BelongsTo $query) => $query->withTrashed(),
            'location' => fn (BelongsTo $query) => $query->withTrashed(),
        ])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereLike('document_number', '%'.$search.'%')->orWhereLike('notes', '%'.$search.'%')
                ->orWhereHas('salesInvoice', fn (Builder $po): Builder => $po->whereLike('document_number', '%'.$search.'%'))
                ->orWhereHas('customer', fn (Builder $customer) => $customer->whereLike('name', '%'.$search.'%'))
                ->orWhereHas('location', fn (Builder $location) => $location->whereLike('name', '%'.$search.'%'))))
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->validated('customer_id'), fn (Builder $query, int $id) => $query->where('customer_id', $id))
            ->when($request->validated('from'), fn (Builder $query, string $date) => $query->whereDate('delivery_date', '>=', $date))
            ->when($request->validated('until'), fn (Builder $query, string $date) => $query->whereDate('delivery_date', '<=', $date))
            ->orderByDesc('delivery_date')->orderByDesc('id')->paginate(30)
            ->through(fn (DeliveryChallan $record): array => $this->challans->summary($actor, $record));

        return response()->json($page->toArray() + ['can_create' => $actor->can('create', DeliveryChallan::class),
            'bulk_actions' => array_values(array_filter([
                $actor->can('deleteAny', DeliveryChallan::class) ? 'delete' : null,
            ]))])->header('Cache-Control', 'private, no-store');
    }

    public function options(DeliveryChallanRegisterRequest $request, string $type): JsonResponse
    {
        return response()->json($this->challans->options($request->user(), $type, trim((string) $request->validated('search'))));
    }

    public function salesInvoice(DeliveryChallanRegisterRequest $request, int $salesInvoice): JsonResponse
    {
        return response()->json(['data' => $this->challans->orderData($request->user(), $salesInvoice, $request->integer('record_id') ?: null)]);
    }

    public function show(Request $request, int $deliveryChallan): JsonResponse
    {
        return response()->json(['data' => $this->challans->detail($request->user(), $this->challans->find($request->user(), $deliveryChallan))]);
    }

    public function store(DeliveryChallanRegisterRequest $request): JsonResponse
    {
        $record = $this->challans->save($request->user(), null, $request->validated());

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    public function update(DeliveryChallanRegisterRequest $request, int $deliveryChallan): JsonResponse
    {
        $record = $this->challans->save($request->user(), $deliveryChallan, $request->validated());

        return response()->json(['data' => $this->challans->detail($request->user(), $record)]);
    }

    public function action(DeliveryChallanRegisterRequest $request, int $deliveryChallan, string $action): JsonResponse
    {
        $this->challans->act($request->user(), [$deliveryChallan], $action, $request->validated('revision'));

        return response()->json(['data' => ['id' => $deliveryChallan]]);
    }

    public function bulk(DeliveryChallanRegisterRequest $request, string $action): JsonResponse
    {
        $ids = array_map(intval(...), $request->validated('ids'));
        $this->challans->act($request->user(), $ids, $action, bulk: true);

        return response()->json(['data' => ['count' => count($ids)]]);
    }
}
