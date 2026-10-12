<?php

namespace App\Services\Expense;

use App\ExpenseConfigurationMatcher;
use App\Models\ExpenseConfiguration;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use Illuminate\Support\Collection;

class ExpenseCalculationService
{
    use BuildsExpenseContext;

    public function __construct(
        protected ExpenseConfigurationMatcher $matcher
    ) {}

    public function autoCalculateDcrExpenses(SalesDcr $dcr): void
    {

        $context = $this->buildExpenseContext($dcr);

        $configs = $this->matcher
            ->getApplicableConfigurations($dcr, context: $context)
            ->groupBy('expense_type_id');

        $dcr->expenses()->autoCalculated()->delete();

        if ($configs->isEmpty()) {
            $dcr->recalculateTotalExpense();

            return;
        }

        $expenses = collect();

        foreach ($configs as $expenseTypeId => $groupConfigs) {

            $amount = $this->executeRuleChain($groupConfigs, $context);

            if ($amount <= 0) {
                continue;
            }

            $expenses->push(new SalesDcrExpense([
                'sales_dcr_id' => $dcr->id,
                'expense_type_id' => $expenseTypeId,
                'quantity' => 1,
                'rate' => $amount,
                'amount' => $amount,
                'is_auto_calculated' => true,
                'meta' => [
                    'rule_count' => $groupConfigs->count(),
                    'context' => $context,
                ],
            ]));
        }

        if ($expenses->isNotEmpty()) {
            $dcr->expenses()->saveMany($expenses);
        }

        $dcr->recalculateTotalExpense();
    }

    /**
     * 🔥 CORE ENGINE: Executes rules sequentially
     */
    protected function executeRuleChain(Collection $configs, array $facts): float
    {
        $amount = 0;

        // 🔥 Sort by priority (HIGH → LOW)
        $configs = $configs->sortByDesc('priority');

        foreach ($configs as $config) {

            $result = match ($config->calculation_strategy) {

                'flat' => (float) $config->rate,

                'per_km' => $facts['distance'] * $config->rate,

                'per_visit' => $facts['visit_count'] * $config->rate,

                'slab' => $this->calculateSlab($config, $facts),

                'multiplier' => $this->applyMultiplier($amount, $config),

                default => 0,
            };

            $amount = $this->applyCaps($result, $config);
        }

        return round($amount, 2);
    }

    /**
     * 🔥 SLAB ENGINE
     */
    protected function calculateSlab(ExpenseConfiguration $config, array $facts): float
    {
        $value = $facts['distance'] ?? 0;

        $slab = $config->slabs
            ->sortBy(fn ($s) => $s->min_value ?? -INF)
            ->first(fn ($s) => ($s->min_value === null || $value >= $s->min_value) &&
                ($s->max_value === null || $value <= $s->max_value)
            );

        if (! $slab) {
            return 0;
        }

        if ($slab->flat_amount !== null) {
            return (float) $slab->flat_amount;
        }

        return $value * (float) $slab->rate;
    }

    /**
     * 🔥 MULTIPLIER (uses previous amount)
     */
    protected function applyMultiplier(float $baseAmount, ExpenseConfiguration $config): float
    {
        return $baseAmount * (float) $config->rate;
    }

    protected function applyCaps(float $amount, ExpenseConfiguration $config): float
    {
        if ($config->min_amount !== null) {
            $amount = max($amount, (float) $config->min_amount);
        }

        if ($config->max_amount !== null) {
            $amount = min($amount, (float) $config->max_amount);
        }

        return $amount;
    }
}
