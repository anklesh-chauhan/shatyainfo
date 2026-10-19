<?php

namespace App\Models;

use App\Enums\InventoryDocumentStatus;
use App\Services\Inventory\InventoryService;
use App\Traits\HasOperationalApproval;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

class InventoryTransfer extends BaseModel
{
    use HasOperationalApproval;

    protected $fillable = [
        'transfer_number',
        'item_master_id',
        'from_location_master_id',
        'to_location_master_id',
        'quantity',
        'unit_cost',
        'remarks',
        'status',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'status' => InventoryDocumentStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class, 'item_master_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(LocationMaster::class, 'from_location_master_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(LocationMaster::class, 'to_location_master_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'reference');
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    public function isEditable(): bool
    {
        return $this->status === InventoryDocumentStatus::Draft && ! $this->isPosted() && ! $this->approvalDocumentIsLocked();
    }

    public function post(): void
    {
        app(InventoryService::class)->postTransfer($this, Auth::id());
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (InventoryTransfer $transfer): void {
            $transfer->transfer_number ??= NumberSeries::getNextNumber(InventoryTransfer::class);
            $transfer->status ??= InventoryDocumentStatus::Draft;
        });

        static::created(function (InventoryTransfer $transfer): void {
            NumberSeries::incrementNextNumber(InventoryTransfer::class);
        });
    }
}
