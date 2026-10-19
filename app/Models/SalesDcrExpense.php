<?php

namespace App\Models;

use App\Traits\LocksWithApprovalDocument;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesDcrExpense extends BaseModel
{
    use LocksWithApprovalDocument;

    public function approvalDocument(): ?Model
    {
        return $this->salesDcr()->first();
    }

    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'sales_dcr_id',
        'expense_type_id',
        'transport_mode_id',
        'amount',
        'is_auto_calculated',
        'quantity',
        'rate',
        'meta',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'decimal:2',
        'rate' => 'decimal:2',
        'is_auto_calculated' => 'boolean',
        'meta' => 'array',
    ];

    public function salesDcr(): BelongsTo
    {
        return $this->belongsTo(SalesDcr::class);
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class);
    }

    public function transportMode(): BelongsTo
    {
        return $this->belongsTo(TransportMode::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesDcrExpenseItem::class);
    }

    public function scopeAutoCalculated($query)
    {
        return $query->where('is_auto_calculated', true);
    }

    public function scopeManual($query)
    {
        return $query->where('is_auto_calculated', false);
    }

    protected static function booted(): void
    {
        static::saved(function (self $expense): void {
            $expense->salesDcr?->recalculateTotalExpense();
        });

        static::deleted(function (self $expense): void {
            $expense->salesDcr?->recalculateTotalExpense();
        });

        static::restored(function (self $expense): void {
            $expense->salesDcr?->recalculateTotalExpense();
        });
    }
}
