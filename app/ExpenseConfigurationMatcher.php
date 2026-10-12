<?php

namespace App;

use App\Models\ExpenseConfiguration;
use App\Models\SalesDcr;
use App\Services\Expense\BuildsExpenseContext;
use Illuminate\Database\Eloquent\Collection;

class ExpenseConfigurationMatcher
{
    use BuildsExpenseContext;

    public function getApplicableConfigurations(
        SalesDcr $dcr,
        ?int $expenseTypeId = null,
        ?int $transportModeId = null,
        array $context = []
    ): Collection {

        $query = ExpenseConfiguration::query()
            ->with(['conditions', 'slabs'])
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $dcr->dcr_date)
            ->where(function ($q) use ($dcr) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $dcr->dcr_date);
            });

        if ($expenseTypeId) {
            $query->where('expense_type_id', $expenseTypeId);
        }

        if ($transportModeId) {
            $query->whereHas('transportModes', function ($q) use ($transportModeId) {
                $q->where('transport_mode_id', $transportModeId);
            });
        }

        $query->where(function ($q) use ($dcr) {
            $q->whereDoesntHave('territories')
                ->orWhereHas('territories', fn ($q2) => $q2->where('territory_id', $dcr->territory_id)
                );
        });

        $configs = $query->orderByDesc('priority')->get();

        $facts = $this->buildExpenseContext($dcr, $context);

        return $configs->filter(fn ($config) => $this->matchConditions($config, $facts)
        );
    }

    protected function matchConditions($config, $facts): bool
    {
        foreach ($config->conditions as $condition) {
            if (! $this->evaluate($condition, $facts)) {
                return false;
            }
        }

        return true;
    }

    protected function evaluate($condition, $facts): bool
    {
        if (! array_key_exists($condition->condition_key, $facts)) {
            return false;
        }

        $factValue = $facts[$condition->condition_key];
        if (is_array($factValue)) {
            return match ($condition->operator) {
                '=' => in_array((string) $condition->value, $factValue, true),
                '!=' => ! in_array((string) $condition->value, $factValue, true),
                default => false,
            };
        }

        $conditionValue = $this->castConditionValue($condition->value, $factValue);

        if ($factValue === null) {
            return false;
        }

        return match ($condition->operator) {
            '=' => $factValue == $conditionValue,
            '!=' => $factValue != $conditionValue,
            '>' => $factValue > $conditionValue,
            '>=' => $factValue >= $conditionValue,
            '<' => $factValue < $conditionValue,
            '<=' => $factValue <= $conditionValue,
            default => false,
        };
    }

    protected function castConditionValue(mixed $raw, mixed $factValue): mixed
    {
        if (is_bool($factValue)) {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        if (is_int($factValue)) {
            return (int) $raw;
        }

        if (is_float($factValue)) {
            return (float) $raw;
        }

        if (is_numeric($factValue)) {
            return (float) $raw;
        }

        return (string) $raw;
    }
}
