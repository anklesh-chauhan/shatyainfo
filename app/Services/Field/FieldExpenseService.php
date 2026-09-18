<?php

namespace App\Services\Field;

use App\Models\ExpenseType;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Models\User;
use App\Services\Expense\ExpenseCalculationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FieldExpenseService
{
    public function __construct(
        private FieldDcrService $dcrService,
        private ExpenseCalculationService $expenseCalculation,
    ) {}

    /**
     * @return Collection<int, SalesDcrExpense>
     */
    public function listForDcr(User $user, SalesDcr $dcr): Collection
    {
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
        $this->dcrService->assertOwned($user, $dcr);
        $this->dcrService->assertEditable($dcr);

        return SalesDcrExpense::query()->create([
            'sales_dcr_id' => $dcr->id,
            'expense_type_id' => $data['expense_type_id'],
            'transport_mode_id' => $data['transport_mode_id'] ?? null,
            'amount' => $data['amount'],
            'quantity' => $data['quantity'] ?? null,
            'rate' => $data['rate'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'meta' => $data['meta'] ?? null,
            'is_auto_calculated' => false,
        ])->load(['expenseType', 'transportMode']);
    }

    /**
     * @param  array{expense_type_id?: int, amount?: float|int|string, remarks?: string|null, quantity?: float|int|string|null, rate?: float|int|string|null, transport_mode_id?: int|null, meta?: array<string, mixed>|null}  $data
     */
    public function updateManual(User $user, SalesDcr $dcr, SalesDcrExpense $expense, array $data): SalesDcrExpense
    {
        $this->dcrService->assertOwned($user, $dcr);
        $this->dcrService->assertEditable($dcr);
        $this->assertExpenseBelongsToDcr($dcr, $expense);
        $this->assertManual($expense);

        $expense->update(collect($data)->only([
            'expense_type_id',
            'transport_mode_id',
            'amount',
            'quantity',
            'rate',
            'remarks',
            'meta',
        ])->all());

        return $expense->fresh(['expenseType', 'transportMode']);
    }

    public function deleteManual(User $user, SalesDcr $dcr, SalesDcrExpense $expense): void
    {
        $this->dcrService->assertOwned($user, $dcr);
        $this->dcrService->assertEditable($dcr);
        $this->assertExpenseBelongsToDcr($dcr, $expense);
        $this->assertManual($expense);

        $expense->delete();
    }

    /**
     * @return Collection<int, SalesDcrExpense>
     */
    public function autoCalculate(User $user, SalesDcr $dcr): Collection
    {
        $this->dcrService->assertOwned($user, $dcr);
        $this->dcrService->assertEditable($dcr);

        $this->expenseCalculation->autoCalculateDcrExpenses($dcr->fresh());

        return $this->listForDcr($user, $dcr->fresh());
    }

    /**
     * @return Collection<int, ExpenseType>
     */
    public function activeTypes(): Collection
    {
        return ExpenseType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    private function assertExpenseBelongsToDcr(SalesDcr $dcr, SalesDcrExpense $expense): void
    {
        if ((int) $expense->sales_dcr_id !== (int) $dcr->id) {
            abort(404, 'Expense not found.');
        }
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
