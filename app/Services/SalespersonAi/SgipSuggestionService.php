<?php

namespace App\Services\SalespersonAi;

use App\Models\AccountMaster;
use App\Models\Employee;
use App\Models\ItemMaster;
use App\Models\SgipDistributionItem;
use App\Models\SgipLimit;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SgipSuggestionService
{
    /**
     * @return array{
     *     suggestions: list<array{item_master_id: int, item_name: string, quantity: int, reason: string}>,
     *     warnings: list<array{item_master_id: int|null, item_name: string|null, message: string}>,
     *     limits: list<array{period: string, applies_to: string, item_type: string, max_quantity: int|null, used_quantity: int, remaining_quantity: int|null}>,
     *     summary: string
     * }
     */
    public function forVisit(Visit $visit): array
    {
        $account = $visit->primaryCompany();

        if (! $account instanceof AccountMaster) {
            return [
                'suggestions' => [],
                'warnings' => [],
                'limits' => [],
                'summary' => 'No account is linked to this visit, so no sample suggestion is available.',
            ];
        }

        $limits = $this->applicableLimits($visit, $account);
        $usage = $this->usageByLimit($limits, $account, $visit);
        $limitRows = $limits->map(function (SgipLimit $limit) use ($usage): array {
            $used = $usage[$limit->id] ?? 0;
            $max = $limit->max_quantity;

            return [
                'period' => (string) $limit->period,
                'applies_to' => (string) $limit->applies_to,
                'item_type' => (string) $limit->item_type,
                'max_quantity' => $max !== null ? (int) $max : null,
                'used_quantity' => $used,
                'remaining_quantity' => $max !== null ? max(0, (int) $max - $used) : null,
            ];
        })->values()->all();

        $suggestions = [];
        $warnings = [];

        foreach ($this->previousItems($visit, $account) as $line) {
            $item = $line->item;

            if (! $item instanceof ItemMaster) {
                continue;
            }

            $remaining = $this->tightestRemaining($limits, $usage, $item);
            $lastQuantity = max(1, (int) $line->quantity);

            if ($remaining === 0) {
                $warnings[] = [
                    'item_master_id' => $item->id,
                    'item_name' => $item->item_name,
                    'message' => $item->item_name.' has no quantity left under the recorded SGIP limit.',
                ];

                continue;
            }

            $quantity = $remaining === null ? $lastQuantity : min($lastQuantity, $remaining);
            $suggestions[] = [
                'item_master_id' => $item->id,
                'item_name' => (string) $item->item_name,
                'quantity' => $quantity,
                'reason' => $remaining === null
                    ? 'Last recorded quantity for this account was '.$lastQuantity.'. No quantity limit is recorded.'
                    : 'Last recorded quantity was '.$lastQuantity.'. '.$remaining.' remains under the tightest SGIP limit.',
            ];
        }

        $summary = $this->summary($account, $suggestions, $warnings, $limitRows);

        return [
            'suggestions' => $suggestions,
            'warnings' => $warnings,
            'limits' => $limitRows,
            'summary' => $summary,
        ];
    }

    /**
     * @return Collection<int, SgipLimit>
     */
    private function applicableLimits(Visit $visit, AccountMaster $account): Collection
    {
        $employeeId = Employee::query()->where('login_id', $visit->employee_id)->value('id');

        return SgipLimit::query()
            ->where(function ($query) use ($visit, $account, $employeeId): void {
                $query->where('applies_to', 'global')
                    ->orWhere(function ($accountLimit) use ($account): void {
                        $accountLimit->where('applies_to', 'account')
                            ->where('applies_to_id', $account->id);
                    })
                    ->orWhere(function ($territoryLimit) use ($visit): void {
                        $territoryLimit->where('applies_to', 'territory')
                            ->where('applies_to_id', $visit->territory_id);
                    });

                if ($employeeId) {
                    $query->orWhere(function ($employeeLimit) use ($employeeId): void {
                        $employeeLimit->where('applies_to', 'employee')
                            ->where('applies_to_id', $employeeId);
                    });
                }
            })
            ->get();
    }

    /**
     * @param  Collection<int, SgipLimit>  $limits
     * @return array<int, int>
     */
    private function usageByLimit(Collection $limits, AccountMaster $account, Visit $visit): array
    {
        $employeeId = Employee::query()->where('login_id', $visit->employee_id)->value('id');
        $usage = [];

        foreach ($limits as $limit) {
            if ($limit->max_quantity === null) {
                $usage[$limit->id] = 0;

                continue;
            }

            [$from, $to] = $this->periodRange($visit->visit_date ? Carbon::parse($visit->visit_date) : today(), (string) $limit->period);

            $usage[$limit->id] = (int) SgipDistributionItem::query()
                ->whereHas('item', function ($query) use ($limit): void {
                    $query->where(function ($inner) use ($limit): void {
                        $inner->where('item_type', $limit->item_type)
                            ->orWhere('category_type', $limit->item_type);
                    });
                })
                ->whereHas('distribution', function ($query) use ($limit, $account, $employeeId, $visit, $from, $to): void {
                    $query->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])
                        ->whereIn('approval_status', ['submitted', 'approved']);

                    match ($limit->applies_to) {
                        'account' => $query->where('account_master_id', $account->id),
                        'employee' => $query->where('employee_id', $employeeId),
                        'territory' => $query->where('territory_id', $visit->territory_id),
                        default => null,
                    };
                })
                ->sum('quantity');
        }

        return $usage;
    }

    /**
     * @return Collection<int, SgipDistributionItem>
     */
    private function previousItems(Visit $visit, AccountMaster $account): Collection
    {
        return SgipDistributionItem::query()
            ->whereHas('distribution', function ($query) use ($visit, $account): void {
                $query->where('account_master_id', $account->id)
                    ->where('user_id', $visit->employee_id)
                    ->where('visit_id', '!=', $visit->id);
            })
            ->with('item')
            ->orderByDesc('id')
            ->get()
            ->unique('item_master_id')
            ->values();
    }

    /**
     * @param  Collection<int, SgipLimit>  $limits
     * @param  array<int, int>  $usage
     */
    private function tightestRemaining(Collection $limits, array $usage, ItemMaster $item): ?int
    {
        $remaining = null;

        foreach ($limits as $limit) {
            if ($limit->max_quantity === null || ! $this->limitMatchesItem($limit, $item)) {
                continue;
            }

            $left = max(0, (int) $limit->max_quantity - ($usage[$limit->id] ?? 0));
            $remaining = $remaining === null ? $left : min($remaining, $left);
        }

        return $remaining;
    }

    private function limitMatchesItem(SgipLimit $limit, ItemMaster $item): bool
    {
        $type = $item->item_type?->value ?? (string) $item->item_type;

        return $type === $limit->item_type || (string) $item->category_type === (string) $limit->item_type;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodRange(Carbon $date, string $period): array
    {
        return match ($period) {
            'daily' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            'yearly' => [$date->copy()->startOfYear(), $date->copy()->endOfYear()],
            default => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
        };
    }

    /**
     * @param  list<array{item_master_id: int, item_name: string, quantity: int, reason: string}>  $suggestions
     * @param  list<array{item_master_id: int|null, item_name: string|null, message: string}>  $warnings
     * @param  list<array{period: string, applies_to: string, item_type: string, max_quantity: int|null, used_quantity: int, remaining_quantity: int|null}>  $limits
     */
    private function summary(AccountMaster $account, array $suggestions, array $warnings, array $limits): string
    {
        if ($warnings !== []) {
            return $warnings[0]['message'];
        }

        if ($suggestions !== []) {
            $first = $suggestions[0];

            return 'Suggested sample for '.$account->name.': '.$first['item_name'].' x '.$first['quantity'].'.';
        }

        if ($limits === []) {
            return 'No earlier samples are recorded for '.$account->name.'.';
        }

        $limit = $limits[0];
        $cap = $limit['max_quantity'] === null ? 'no quantity cap' : $limit['remaining_quantity'].' remaining of '.$limit['max_quantity'];

        return 'No earlier samples are recorded for '.$account->name.'. The '.$limit['period'].' '.$limit['applies_to'].' limit has '.$cap.'.';
    }
}
