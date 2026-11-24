<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DcrExpenseLifecycleRequest;
use App\Http\Requests\Api\V1\ListRegisterExpensesRequest;
use App\Http\Requests\Api\V1\SaveRegisterExpenseRequest;
use App\Http\Requests\Api\V1\StoreDcrExpenseReceiptRequest;
use App\Http\Requests\Api\V1\StoreDcrExpenseRequest;
use App\Http\Requests\Api\V1\UpdateDcrExpenseRequest;
use App\Http\Resources\Api\V1\SalesDcrExpenseResource;
use App\Models\SalesDcrExpense;
use App\Services\Expense\ExpenseCalculationService;
use App\Services\Field\DcrExpenseRegister;
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

    public function register(ListRegisterExpensesRequest $request, DcrExpenseRegister $register): AnonymousResourceCollection|JsonResponse
    {
        if ($request->boolean('register')) {
            return response()->json($register->index($request->user(), $request->validated()));
        }

        return SalesDcrExpenseResource::collection(SalesDcrExpense::query()
            ->whereHas('salesDcr', fn ($reports) => $reports->where('user_id', $request->user()->id))
            ->with(['expenseType', 'salesDcr:id,dcr_date'])
            ->latest('id')->paginate(25));
    }

    public function registerDetail(ListRegisterExpensesRequest $request, int $expense, DcrExpenseRegister $register): JsonResponse
    {
        return response()->json(['data' => $register->detail($request->user(), $expense, $request->validated('scope') === 'team')]);
    }

    public function registerOptions(ListRegisterExpensesRequest $request, DcrExpenseRegister $register): JsonResponse
    {
        return response()->json($register->options($request->user(), $request->validated()));
    }

    public function registerStore(SaveRegisterExpenseRequest $request, DcrExpenseRegister $register): JsonResponse
    {
        return response()->json(['data' => $register->save($request->user(), $request->validated())], 201);
    }

    public function registerUpdate(SaveRegisterExpenseRequest $request, int $expense, DcrExpenseRegister $register): JsonResponse
    {
        return response()->json(['data' => $register->save($request->user(), $request->validated(), $expense)]);
    }

    public function registerLifecycle(DcrExpenseLifecycleRequest $request, DcrExpenseRegister $register): JsonResponse
    {
        $updated = $register->lifecycle($request->user(), $request->validated('scope') === 'team',
            $request->validated('action'), array_map('intval', $request->validated('ids')));

        return response()->json(['data' => ['updated' => $updated]]);
    }

    public function registerRecalculate(ListRegisterExpensesRequest $request, DcrExpenseRegister $register, ExpenseCalculationService $calculation): JsonResponse
    {
        $register->recalculate($request->user(), $request->integer('sales_dcr_id'), $request->validated('scope') === 'team', $calculation);

        return response()->json(['data' => ['calculated' => true]]);
    }

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
