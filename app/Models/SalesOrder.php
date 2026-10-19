<?php

namespace App\Models;

use App\Traits\HasOperationalApproval;

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

    public function accountMaster()
    {
        return $this->belongsTo(AccountMaster::class);
    }

    public function quotes()
    {
        return $this->belongsToMany(Quote::class, 'quote_sales_order_pivot');
    }

    public function salesInvoices()
    {
        return $this->belongsToMany(SalesInvoice::class, 'sales_order_sales_invoice_pivot');
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
