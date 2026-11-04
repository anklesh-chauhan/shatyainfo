<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDcrExpenseReceiptRequest;
use App\Http\Requests\Api\V1\StoreDcrExpenseRequest;
use App\Http\Requests\Api\V1\UpdateDcrExpenseRequest;
use App\Http\Resources\Api\V1\SalesDcrExpenseResource;
use App\Models\SalesDcrExpense;
use App\Services\Field\FieldDcrService;
use App\Services\Field\FieldExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DcrExpenseController extends Controller
{
    public function __construct(
        private FieldDcrService $dcrService,
        private FieldExpenseService $expenseService,
    ) {}

    public function index(Request $request, int $dcr): AnonymousResourceCollection
    {
        $record = $this->dcrService->findOwned($request->user(), $dcr);

        return SalesDcrExpenseResource::collection(
            $this->expenseService->listForDcr($request->user(), $record),
        );
    }

    public function store(StoreDcrExpenseRequest $request, int $dcr): SalesDcrExpenseResource
    {
        $record = $this->dcrService->findOwned($request->user(), $dcr);

        return new SalesDcrExpenseResource(
            $this->expenseService->storeManual($request->user(), $record, $request->validated()),
        );
    }

    public function update(UpdateDcrExpenseRequest $request, int $dcr, int $expense): SalesDcrExpenseResource
    {
        $record = $this->dcrService->findOwned($request->user(), $dcr);
        $expenseModel = SalesDcrExpense::query()->findOrFail($expense);

        return new SalesDcrExpenseResource(
            $this->expenseService->updateManual(
                $request->user(),
                $record,
                $expenseModel,
                $request->validated(),
            ),
        );
    }

    public function destroy(Request $request, int $dcr, int $expense): JsonResponse
    {
        $record = $this->dcrService->findOwned($request->user(), $dcr);
        $expenseModel = SalesDcrExpense::query()->findOrFail($expense);

        $this->expenseService->deleteManual($request->user(), $record, $expenseModel);

        return response()->json([
            'message' => 'Expense deleted.',
        ]);
    }

    public function autoCalculate(Request $request, int $dcr): AnonymousResourceCollection
    {
        $record = $this->dcrService->findOwned($request->user(), $dcr);

        return SalesDcrExpenseResource::collection(
            $this->expenseService->autoCalculate($request->user(), $record),
        );
    }

    public function storeReceipt(StoreDcrExpenseReceiptRequest $request, int $dcr, int $expense): JsonResponse
    {
        $record = $this->dcrService->findOwned($request->user(), $dcr);
        $media = $this->expenseService->storeReceipt($request->user(), $record,
            SalesDcrExpense::query()->findOrFail($expense), $request->file('receipt'));

        return response()->json(['data' => [
            'id' => $media->id, 'name' => $media->original_name,
            'mime_type' => $media->mime_type, 'size' => $media->size,
        ]]);
    }

    public function receipt(Request $request, int $dcr, int $expense, int $receipt): StreamedResponse
    {
        $record = $this->dcrService->findOwned($request->user(), $dcr);
        $media = $this->expenseService->findReceipt($request->user(), $record,
            SalesDcrExpense::query()->findOrFail($expense), $receipt);
        abort_unless(Storage::disk('local')->exists($media->path), 404);

        return Storage::disk('local')->download($media->path, $media->original_name, [
            'Content-Type' => $media->mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
