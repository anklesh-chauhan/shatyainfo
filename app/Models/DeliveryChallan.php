<?php

namespace App\Models;

use App\Enums\DeliveryChallanStatus;
use App\Services\Inventory\InventoryService;
use App\Traits\HasOperationalApproval;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class DeliveryChallan extends BaseModel
{
    use HasOperationalApproval;

    protected $fillable = [
        'document_number',
        'sales_invoice_id',
        'customer_id',
        'location_master_id',
        'delivery_date',
        'status',
        'notes',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'status' => DeliveryChallanStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'customer_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(LocationMaster::class, 'location_master_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryChallanLine::class);
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    public function isEditable(): bool
    {
        return $this->status === DeliveryChallanStatus::Draft && ! $this->isPosted() && ! $this->approvalDocumentIsLocked();
    }

    public function post(): void
    {
        app(InventoryService::class)->postDeliveryChallan($this, Auth::id());
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (DeliveryChallan $challan): void {
            $challan->document_number ??= NumberSeries::getNextNumber(DeliveryChallan::class);
            $challan->status ??= DeliveryChallanStatus::Draft;
        });

        static::created(function (DeliveryChallan $challan): void {
            NumberSeries::incrementNextNumber(DeliveryChallan::class);
        });
    }
}
