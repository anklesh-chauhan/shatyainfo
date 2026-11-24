<?php

namespace App\Services\Reports;

use App\Models\FollowUp;
use Illuminate\Database\Eloquent\Builder;

class OverdueFollowUpQuery
{
    public function period(Builder $query, ?string $from, ?string $until): Builder
    {
        foreach (['>=' => $from, '<=' => $until] as $operator => $date) {
            if ($date !== null && $date !== '') {
                $query->where(function (Builder $due) use ($operator, $date): void {
                    $due->whereDate('next_follow_up_date', $operator, $date)
                        ->orWhere(fn (Builder $original): Builder => $original
                            ->whereNull('next_follow_up_date')->whereDate('follow_up_date', $operator, $date));
                });
            }
        }

        return $query;
    }

    /**
     * @var list<string>
     */
    public const CLOSED_STATUSES = ['Completed', 'Cancelled', 'Canceled', 'Closed', 'Done'];

    public function builder(): Builder
    {
        return FollowUp::query()
            ->with(['user', 'status', 'followupable', 'contactDetail'])
            ->where(function (Builder $query): void {
                $query
                    ->whereDate('next_follow_up_date', '<', today())
                    ->orWhere(function (Builder $withoutNext): void {
                        $withoutNext
                            ->whereNull('next_follow_up_date')
                            ->whereDate('follow_up_date', '<', today());
                    });
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('follow_up_status_id')
                    ->orWhereHas(
                        'status',
                        fn (Builder $statusQuery): Builder => $statusQuery->whereNotIn('name', self::CLOSED_STATUSES)
                    );
            });
    }
}
