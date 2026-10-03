<?php

namespace App\Services\Field;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class FieldRecordedAt
{
    public static function resolve(array $data): CarbonImmutable
    {
        $time = isset($data['recorded_at'])
            ? CarbonImmutable::parse($data['recorded_at'])->setTimezone(config('app.timezone'))
            : CarbonImmutable::now();
        if ($time->greaterThan(now()->addMinutes(5)) || $time->lessThan(now()->subDays(7))) {
            throw ValidationException::withMessages([
                'recorded_at' => 'Capture time must be within the last seven days and no more than five minutes ahead.',
            ]);
        }

        return $time;
    }
}
