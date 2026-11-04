<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecondarySalesStatement extends Model
{
    public const FINAL_STATUSES = ['approved', 'rejected', 'reversed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'reversed_at' => 'datetime', 'balance_rules' => 'array'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SecondarySalesLine::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(SecondarySalesBalance::class);
    }

    public function stockist(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'stockist_id');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_statement_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(SecondarySalesCorrection::class, 'reversed_by_correction_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(SecondarySalesCorrection::class, 'secondary_sales_statement_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    protected static function booted(): void
    {
        static::updating(function (self $statement): void {
            if ($statement->getOriginal('status') === 'approved' && $statement->status === 'reversed'
                && array_diff(array_keys($statement->getDirty()), ['status', 'reversed_at', 'reversed_by_correction_id', 'updated_at']) === []
                && $statement->reversed_by_correction_id !== null) {
                return;
            }
            abort_if(in_array($statement->getOriginal('status'), self::FINAL_STATUSES, true), 409, 'Reviewed statements are immutable.');
        });
        static::deleting(fn () => abort(409, 'Retain statement audit history.'));
    }
}
