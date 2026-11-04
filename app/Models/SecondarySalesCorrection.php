<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audited reversal or replacement of an approved secondary-sales statement.
 *
 * @property array{gross_paise: int, discount_paise: int, returns_paise: int, breakage_expiry_paise: int, net_paise: int, net_base_quantity: float, line_count: int} $original_summary
 * @property ?array{gross_paise: int, discount_paise: int, returns_paise: int, breakage_expiry_paise: int, net_paise: int, net_base_quantity: float, line_count: int} $replacement_summary
 */
class SecondarySalesCorrection extends Model
{
    protected $guarded = [];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(SecondarySalesStatement::class, 'secondary_sales_statement_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(SecondarySalesStatement::class, 'replacement_statement_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    protected function casts(): array
    {
        return ['original_summary' => 'array', 'replacement_summary' => 'array', 'released_groups' => 'array', 'released_adjustments' => 'array',
            'requested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $correction): void {
            abort_if($correction->isDirty(['secondary_sales_statement_id', 'replacement_statement_id', 'kind', 'requested_by', 'reason', 'original_summary', 'requested_at']), 409, 'Correction evidence is permanent.');
            abort_if(in_array($correction->getOriginal('status'), ['approved', 'rejected'], true), 409, 'Reviewed corrections are immutable.');
        });
        static::deleting(fn () => abort(409, 'Retain correction audit history.'));
    }
}
