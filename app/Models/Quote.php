<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;

class Quote extends SalesDocument
{
    use HasApprovalWorkflow;

    protected $table = 'quotes';

    protected $fillable = [
        ...parent::FILLABLE,
        'account_master_id',
        'expiration_date',
        'accepted_at',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'accepted_at' => 'datetime',
        'expiration_date' => 'date',
    ];

    public function salesOrders()
    {
        return $this->belongsToMany(SalesOrder::class, 'quote_sales_order_pivot', 'quote_id', 'sales_order_id');
    }

    public function salesInvoices()
    {
        return $this->belongsToMany(SalesInvoice::class, 'quote_sales_invoice_pivot', 'quote_id', 'sales_invoice_id');
    }

    public function accountMaster()
    {
        return $this->belongsTo(AccountMaster::class);
    }

    protected function resolveApprovalTerritoryId(): ?int
    {
        return $this->accountMaster?->territory_id;
    }
}
