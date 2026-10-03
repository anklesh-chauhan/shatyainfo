<?php

namespace App\Traits;

use App\Filament\GlobalSearch\RecordLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

trait HasRelatedGlobalSearch
{
    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        if (! property_exists(static::class, 'relatedGlobalSearchAttributes')) {
            return [];
        }

        return static::$relatedGlobalSearchAttributes;
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if ($record === null) {
            return null;
        }

        $label = RecordLabel::make($record);

        if (filled($label)) {
            return $label;
        }

        $titleAttribute = static::getRecordTitleAttribute();

        if (filled($titleAttribute) && ! str_contains($titleAttribute, '.')) {
            $value = $record->getAttribute($titleAttribute);

            if (is_scalar($value) && filled($value)) {
                return (string) $value;
            }
        }

        return static::getModelLabel();
    }
}
