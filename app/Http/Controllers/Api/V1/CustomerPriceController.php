<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomerPriceRegisterRequest;
use App\Http\Requests\Api\V1\DeleteCustomerPricesRequest;
use App\Http\Requests\Api\V1\SaveCustomerPriceRequest;
use App\Services\Field\FieldCustomerPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerPriceController extends Controller
{
    public function __construct(private FieldCustomerPriceService $prices) {}

    public function index(CustomerPriceRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $page = $this->prices->list($actor, $request->validated());

        return response()->json($page->toArray() + ['can_create' => $this->prices->canCreate($actor)]);
    }

    public function options(CustomerPriceRegisterRequest $request, string $type): JsonResponse
    {
        $options = $this->prices->options($request->user(), $type, $request->validated());

        return response()->json(['data' => $options->items(), 'meta' => ['last_page' => $options->lastPage()]]);
    }

    public function show(Request $request, int $price): JsonResponse
    {
        $actor = $request->user();

        return response()->json(['data' => $this->prices->data($actor, $this->prices->find($actor, $price))]);
    }

    public function store(SaveCustomerPriceRequest $request): JsonResponse
    {
        $actor = $request->user();

        return response()->json(['data' => $this->prices->data($actor, $this->prices->save($actor, null, $request->validated()))], 201);
    }

    public function update(SaveCustomerPriceRequest $request, int $price): JsonResponse
    {
        $actor = $request->user();

        return response()->json(['data' => $this->prices->data($actor, $this->prices->save($actor, $price, $request->validated()))]);
    }

    public function destroy(Request $request, int $price): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->prices->canRead($actor), 403);

        return response()->json(['data' => ['deleted' => $this->prices->delete($actor, [$price])]]);
    }

    public function bulkDelete(DeleteCustomerPricesRequest $request): JsonResponse
    {
        $ids = array_map(intval(...), $request->validated('ids'));

        return response()->json(['data' => ['deleted' => $this->prices->delete($request->user(), $ids)]]);
    }
}
