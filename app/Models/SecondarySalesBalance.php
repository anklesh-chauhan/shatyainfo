<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecondarySalesBalance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'raw' => 'array',
            'errors' => 'array',
            'opening_qty' => 'decimal:6',
            'purchase_qty' => 'decimal:6',
            'primary_free_qty' => 'decimal:6',
            'purchase_return_qty' => 'decimal:6',
            'secondary_sales_qty' => 'decimal:6',
            'secondary_free_qty' => 'decimal:6',
            'sales_return_qty' => 'decimal:6',
            'expiry_breakage_qty' => 'decimal:6',
            'reported_closing_qty' => 'decimal:6',
            'calculated_closing_qty' => 'decimal:6',
            'variance_qty' => 'decimal:6',
            'prior_closing_qty' => 'decimal:6',
            'primary_invoiced_qty' => 'decimal:6',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(SecondarySalesStatement::class, 'secondary_sales_statement_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class, 'item_master_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SecondarySalesLine::class);
    }

    protected static function booted(): void
    {
        static::saving(fn (self $balance) => abort_if(in_array($balance->statement?->status, SecondarySalesStatement::FINAL_STATUSES, true), 409, 'Reviewed balances are immutable.'));
        static::deleting(fn () => abort(409, 'Retain balance audit history.'));
    }
}
