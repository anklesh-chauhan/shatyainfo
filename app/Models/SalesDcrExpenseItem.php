<?php

namespace App\Models;

use App\Traits\LocksWithApprovalDocument;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesDcrExpenseItem extends BaseModel
{
    use LocksWithApprovalDocument;

    public function approvalDocument(): ?Model
    {
        return $this->expense()->first()?->salesDcr()->first();
    }

    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'sales_dcr_expense_id',
        'label',
        'amount',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta' => 'array',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(SalesDcrExpense::class, 'sales_dcr_expense_id');
    }
}
