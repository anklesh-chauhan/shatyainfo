<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RcpaCompetitor extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'rank' => 'integer'];
    }

    public function therapyArea(): BelongsTo
    {
        return $this->belongsTo(AccountReportingMaster::class, 'therapy_area_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            Validator::make($record->getAttributes(), ['therapy_area_id' => ['required', Rule::exists('account_reporting_masters', 'id')->where('kind', 'therapy_area')],
                'rank' => ['required', 'integer', 'between:1,5'], 'name' => ['required', 'string', 'max:255']])->validate();
        });
    }
}
