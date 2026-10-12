<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExpenseConfigurationConditionKey: string implements HasLabel
{
    case DISTANCE = 'distance';
    case TRAVEL_TYPE = 'travel_type';
    case CITY_CLASS = 'city_class';
    case DURATION_HOURS = 'duration_hours';
    case IS_HILL_STATION = 'is_hill_station';

    public function getLabel(): string
    {
        return match ($this) {
            self::DISTANCE => 'Distance',
            self::TRAVEL_TYPE => 'Visit Purpose',
            self::CITY_CLASS => 'City Classification',
            self::DURATION_HOURS => 'Duration (Hours)',
            self::IS_HILL_STATION => 'Is Hill Station',
        };
    }
}
