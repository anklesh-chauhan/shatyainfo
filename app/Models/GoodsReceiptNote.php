<?php

namespace App\Models;

use App\Enums\GoodsReceiptNoteStatus;
use App\Services\Inventory\InventoryService;
use App\Traits\HasOperationalApproval;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class GoodsReceiptNote extends BaseModel
{
    use HasOperationalApproval;

    protected $fillable = [
        'document_number',
        'purchase_order_id',
        'supplier_id',
        'location_master_id',
        'receipt_date',
        'status',
        'notes',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'status' => GoodsReceiptNoteStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'supplier_id');
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
        return $this->hasMany(GoodsReceiptNoteLine::class);
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    public function isEditable(): bool
    {
        return $this->status === GoodsReceiptNoteStatus::Draft && ! $this->isPosted() && ! $this->approvalDocumentIsLocked();
    }

    public function post(): void
    {
        app(InventoryService::class)->postGrn($this, Auth::id());
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (GoodsReceiptNote $grn): void {
            $grn->document_number ??= NumberSeries::getNextNumber(GoodsReceiptNote::class);
            $grn->status ??= GoodsReceiptNoteStatus::Draft;
        });

        static::created(function (GoodsReceiptNote $grn): void {
            NumberSeries::incrementNextNumber(GoodsReceiptNote::class);
        });
    }
}
