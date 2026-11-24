<?php

namespace App\Models;

use App\Traits\HasOperationalApproval;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrder extends SalesDocument
{
    use HasOperationalApproval;

    protected $table = 'sales_orders';

    protected $fillable = [
        ...parent::FILLABLE,
        'account_master_id',
        'delivery_date',
        'order_confirmation_at',
        'other_ref',
    ];

    public function accountMaster(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class);
    }

    public function quotes(): BelongsToMany
    {
        return $this->belongsToMany(Quote::class, 'quote_sales_order_pivot');
    }

    public function salesInvoices(): BelongsToMany
    {
        return $this->belongsToMany(SalesInvoice::class, 'sales_order_sales_invoice_pivot');
    }

    public function fieldOrderState(): HasOne
    {
        return $this->hasOne(FieldOrderState::class);
    }

    protected function resolveApprovalTerritoryId(): ?int
    {
        return $this->accountMaster?->territory_id;
    }

    protected $casts = [
        'account_master_id' => 'integer',
        'delivery_date' => 'date',
        'order_confirmation_at' => 'datetime',
        'date' => 'date',
        'due_date' => 'date',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'canceled_at' => 'datetime',
        'sent_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'deleted_by' => 'integer',
    ];
}
