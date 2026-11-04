<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecondarySalesLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw' => 'array', 'errors' => 'array', 'physically_verified' => 'boolean', 'incentive_frozen' => 'boolean', 'invoice_date' => 'date'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(SecondarySalesStatement::class, 'secondary_sales_statement_id');
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(SecondarySalesBalance::class, 'secondary_sales_balance_id');
    }

    protected static function booted(): void
    {
        static::saving(fn (self $line) => abort_if(in_array($line->statement?->status, SecondarySalesStatement::FINAL_STATUSES, true), 409, 'Reviewed lines are immutable.'));
        static::deleting(fn () => abort(409, 'Retain line audit history.'));
    }
}
