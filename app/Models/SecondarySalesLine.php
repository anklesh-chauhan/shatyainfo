<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecondarySalesLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw' => 'array', 'errors' => 'array', 'physically_verified' => 'boolean', 'invoice_date' => 'date'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(SecondarySalesStatement::class, 'secondary_sales_statement_id');
    }

    protected static function booted(): void
    {
        static::saving(fn (self $line) => abort_if(in_array($line->statement?->status, ['approved', 'rejected'], true), 409, 'Reviewed lines are immutable.'));
        static::deleting(fn () => abort(409, 'Retain line audit history.'));
    }
}
