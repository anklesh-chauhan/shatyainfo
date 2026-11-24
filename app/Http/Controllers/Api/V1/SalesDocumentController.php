<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SalesDocumentKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SalesDocumentPdfController;
use App\Http\Requests\Api\V1\ListSalesDocumentsRequest;
use App\Http\Requests\Api\V1\SalesDocumentActionRequest;
use App\Http\Requests\Api\V1\SaveSalesDocumentRequest;
use App\Http\Requests\Api\V1\StoreInvoiceDeliveryChallanRequest;
use App\Services\Field\FieldSalesDocumentService;
use App\Services\Field\SalesDocumentPricing;
use App\Services\Inventory\InvoiceDeliveryHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesDocumentController extends Controller
{
    public function __construct(private SalesDocumentPricing $pricing, private InvoiceDeliveryHandoff $delivery) {}

    private function documents(Request $request): FieldSalesDocumentService
    {
        return new FieldSalesDocumentService($this->pricing, SalesDocumentKind::forRequest($request), $this->delivery);
    }

    public function index(ListSalesDocumentsRequest $request): JsonResponse
    {
        $actor = $request->user();
        $documents = $this->documents($request)->list($actor, $request->validated());

        return response()->json($documents->toArray() + ['can_create' => $actor->can('create', SalesDocumentKind::forRequest($request)->model())]);
    }

    public function options(ListSalesDocumentsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->documents($request)->options($request->user())]);
    }

    public function items(ListSalesDocumentsRequest $request): JsonResponse
    {
        $items = $this->documents($request)->items($request->user(), $request->validated());

        return response()->json(['data' => $items->items(), 'meta' => ['last_page' => $items->lastPage()]]);
    }

    public function salesPeople(ListSalesDocumentsRequest $request): JsonResponse
    {
        $users = $this->documents($request)->salesPeople($request->user(), $request->validated());

        return response()->json(['data' => $users->items(), 'meta' => ['last_page' => $users->lastPage()]]);
    }

    public function filterOptions(ListSalesDocumentsRequest $request, string $field): JsonResponse
    {
        $options = $this->documents($request)->filterOptions($request->user(), $field, $request->validated());

        return response()->json(['data' => $options->items(), 'meta' => ['last_page' => $options->lastPage()]]);
    }

    public function show(Request $request, int $documentId): JsonResponse
    {
        $actor = $request->user();
        $documents = $this->documents($request);

        return response()->json(['data' => $documents->detail($documents->find($actor, $documentId), $actor)]);
    }

    public function store(SaveSalesDocumentRequest $request): JsonResponse
    {
        $actor = $request->user();
        $documents = $this->documents($request);
        $record = $documents->save($actor, null, $request->validated());

        return response()->json(['data' => $documents->detail($record, $actor)], 201);
    }

    public function update(SaveSalesDocumentRequest $request, int $documentId): JsonResponse
    {
        $actor = $request->user();
        $documents = $this->documents($request);
        $record = $documents->save($actor, $documentId, $request->validated());

        return response()->json(['data' => $documents->detail($record, $actor)]);
    }

    public function destroy(Request $request, int $documentId): JsonResponse
    {
        return response()->json(['data' => $this->documents($request)->act($request->user(), 'delete', [$documentId])]);
    }

    public function action(SalesDocumentActionRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->documents($request)->act($request->user(), $data['action'], array_map(intval(...), $data['ids']))]);
    }

    public function deliveryLocations(ListSalesDocumentsRequest $request, int $documentId): JsonResponse
    {
        $actor = $request->user();
        $this->delivery->authorizeCreate($actor);
        $invoice = $this->delivery->visibleInvoices($actor)->findOrFail($documentId);
        $this->delivery->assertReady($invoice);
        $locations = $this->delivery->locations($request->validated());

        return response()->json(['data' => $locations->items(), 'meta' => ['last_page' => $locations->lastPage()]]);
    }

    public function storeDeliveryChallan(StoreInvoiceDeliveryChallanRequest $request, int $documentId): JsonResponse
    {
        $this->delivery->create($request->user(), $documentId, $request->validated());

        return response()->json(['data' => ['created' => true]], 201);
    }

    public function pdf(Request $request, int $documentId, SalesDocumentPdfController $pdf): Response
    {
        $record = $this->documents($request)->find($request->user(), $documentId);

        $type = SalesDocumentKind::forRequest($request)->module();

        return $request->boolean('download')
            ? $pdf->downloadDocument($record, $type)
            : $pdf->previewDocument($record, $type);
    }
}
