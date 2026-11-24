<?php

namespace App\Services\Field;

use App\Models\ExpenseType;
use App\Models\Media;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Models\User;
use App\Services\Expense\ExpenseCalculationService;
use App\Services\OptionalModuleService;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FieldExpenseService
{
    public function __construct(
        private FieldDcrService $dcrService,
        private ExpenseCalculationService $expenseCalculation,
        private OptionalModuleService $modules,
    ) {}

    /**
     * @return Collection<int, SalesDcrExpense>
     */
    public function listForDcr(User $user, SalesDcr $dcr): Collection
    {
        $this->authorizeModule();
        $this->dcrService->assertOwned($user, $dcr);

        return $dcr->expenses()
            ->with(['expenseType', 'transportMode'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{expense_type_id: int, amount: float|int|string, remarks?: string|null, quantity?: float|int|string|null, rate?: float|int|string|null, transport_mode_id?: int|null, meta?: array<string, mixed>|null}  $data
     */
    public function storeManual(User $user, SalesDcr $dcr, array $data): SalesDcrExpense
    {
        return $this->withOwnerReport($user, $dcr, fn (SalesDcr $report): SalesDcrExpense => SalesDcrExpense::query()->create([
            'sales_dcr_id' => $report->id,
            'expense_type_id' => $data['expense_type_id'],
            'transport_mode_id' => $data['transport_mode_id'] ?? null,
            'amount' => $data['amount'],
            'quantity' => $data['quantity'] ?? null,
            'rate' => $data['rate'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'meta' => $data['meta'] ?? null,
            'is_auto_calculated' => false,
        ])->load(['expenseType', 'transportMode']));
    }

    /**
     * @param  array{expense_type_id?: int, amount?: float|int|string, remarks?: string|null, quantity?: float|int|string|null, rate?: float|int|string|null, transport_mode_id?: int|null, meta?: array<string, mixed>|null}  $data
     */
    public function updateManual(User $user, SalesDcr $dcr, SalesDcrExpense $expense, array $data): SalesDcrExpense
    {
        return $this->withOwnerReport($user, $dcr, function (SalesDcr $report) use ($expense, $data): SalesDcrExpense {
            $expense = $report->expenses()->lockForUpdate()->findOrFail($expense->id);
            $this->assertManual($expense);
            $expense->update(collect($data)->only([
                'expense_type_id', 'transport_mode_id', 'amount', 'quantity', 'rate', 'remarks', 'meta',
            ])->all());

            return $expense->fresh(['expenseType', 'transportMode']);
        });
    }

    public function deleteManual(User $user, SalesDcr $dcr, SalesDcrExpense $expense): void
    {
        $this->withOwnerReport($user, $dcr, function (SalesDcr $report) use ($expense): void {
            $expense = $report->expenses()->lockForUpdate()->findOrFail($expense->id);
            $this->assertManual($expense);
            $expense->delete();
        });
    }

    /**
     * @return Collection<int, SalesDcrExpense>
     */
    public function autoCalculate(User $user, SalesDcr $dcr): Collection
    {
        return $this->withOwnerReport($user, $dcr, function (SalesDcr $report) use ($user): Collection {
            $this->expenseCalculation->autoCalculateDcrExpenses($report);

            return $this->listForDcr($user, $report);
        });
    }

    /**
     * @return Collection<int, ExpenseType>
     */
    public function activeTypes(): Collection
    {
        $this->authorizeModule();

        return ExpenseType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    private function authorizeModule(): void
    {
        abort_unless($this->modules->navigationEnabled('SalesDcrExpense'), 403);
    }

    /**
     * @template T
     *
     * @param  Closure(SalesDcr): T  $operation
     * @return T
     */
    private function withOwnerReport(User $user, SalesDcr $dcr, Closure $operation, bool $editable = true): mixed
    {
        $this->authorizeModule();

        return $dcr->getConnection()->transaction(function () use ($user, $dcr, $operation, $editable): mixed {
            $report = SalesDcr::query()->lockForUpdate()->findOrFail($dcr->id);
            $this->dcrService->assertOwned($user, $report);
            if ($editable) {
                $this->dcrService->assertEditable($report);
            }

            return $operation($report);
        }, attempts: 3);
    }

    public function storeReceipt(User $user, SalesDcr $dcr, SalesDcrExpense $expense, UploadedFile $receipt): Media
    {
        return $this->withOwnerReport($user, $dcr, function (SalesDcr $report) use ($user, $expense, $receipt): Media {
            $expense = $report->expenses()->lockForUpdate()->findOrFail($expense->id);
            $this->assertManual($expense);
            $tenant = hash('sha256', $expense->getConnection()->getDatabaseName());
            $hash = hash_file('sha256', $receipt->getRealPath());
            $path = 'expense-receipts/'.$tenant.'/'.$user->id.'/'.$report->id.'/'.$expense->id.'/'.$hash.'.'.$receipt->extension();
            $existing = $expense->receipts()->where('disk', 'local')->where('path', $path)->first();
            if ($existing) {
                return $existing;
            }
            $stored = Storage::disk('local')->putFileAs(dirname($path), $receipt, basename($path));
            abort_if($stored === false, 503, 'Receipt storage is unavailable. Retry synchronization.');

            return $expense->receipts()->create([
                'disk' => 'local', 'path' => $path,
                'original_name' => $receipt->getClientOriginalName(),
                'mime_type' => $receipt->getMimeType(), 'size' => $receipt->getSize(),
                'captured_at' => now(),
                'is_processed' => true, 'processed_at' => now(), 'processing_status' => 'completed',
            ]);
        });
    }

    public function findReceipt(User $user, SalesDcr $dcr, SalesDcrExpense $expense, int $receipt): Media
    {
        return $this->withOwnerReport($user, $dcr, function (SalesDcr $report) use ($expense, $receipt): Media {
            $expense = $report->expenses()->findOrFail($expense->id);

            return $expense->receipts()->where('disk', 'local')->findOrFail($receipt);
        }, editable: false);
    }

    private function assertManual(SalesDcrExpense $expense): void
    {
        if ($expense->is_auto_calculated) {
            throw ValidationException::withMessages([
                'expense' => 'Auto-calculated expenses cannot be edited manually.',
            ]);
        }
    }
}
