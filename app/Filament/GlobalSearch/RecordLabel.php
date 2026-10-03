<?php

namespace App\Filament\GlobalSearch;

use Illuminate\Database\Eloquent\Model;
use Throwable;

class RecordLabel
{
    public static function make(Model $record): ?string
    {
        foreach (['employee', 'user', 'requester'] as $relation) {
            $name = static::relatedName($record, $relation);

            if (filled($name)) {
                return $name;
            }
        }

        $expenseType = static::relatedName($record, 'expenseType');

        if (filled($expenseType)) {
            return $expenseType;
        }

        $customer = static::relatedName($record, 'customer');

        if (filled($customer)) {
            return $customer;
        }

        $from = static::placeName($record, 'fromAccount', 'name')
            ?? static::placeName($record, 'fromAreaTown', 'area_town');
        $to = static::placeName($record, 'toAccount', 'name')
            ?? static::placeName($record, 'toAreaTown', 'area_town');

        if (filled($from) || filled($to)) {
            return trim(($from ?: 'Unknown').' to '.($to ?: 'Unknown'));
        }

        return null;
    }

    protected static function relatedName(Model $record, string $relation): ?string
    {
        if (! method_exists($record, $relation)) {
            return null;
        }

        try {
            $related = $record->{$relation};
        } catch (Throwable) {
            return null;
        }

        if ($related === null) {
            return null;
        }

        $name = $related->full_name ?? null;

        if (! filled($name)) {
            $name = $related->name ?? null;
        }

        return filled($name) ? trim((string) $name) : null;
    }

    protected static function placeName(Model $record, string $relation, string $attribute): ?string
    {
        if (! method_exists($record, $relation)) {
            return null;
        }

        try {
            $value = $record->{$relation}?->{$attribute};
        } catch (Throwable) {
            return null;
        }

        return filled($value) ? (string) $value : null;
    }
}
