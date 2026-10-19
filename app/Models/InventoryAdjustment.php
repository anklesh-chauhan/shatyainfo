<?php

namespace App\Models;

use App\Enums\InventoryAdjustmentType;
use App\Enums\InventoryDocumentStatus;
use App\Services\Inventory\InventoryService;
use App\Traits\HasOperationalApproval;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

class InventoryAdjustment extends BaseModel
{
    use HasOperationalApproval;

    protected $fillable = [
        'adjustment_number',
        'item_master_id',
        'location_master_id',
        'adjustment_type',
        'quantity',
        'unit_cost',
        'reason',
        'remarks',
        'status',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_type' => InventoryAdjustmentType::class,
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(LocationMaster::class, 'location_master_id');
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
        app(InventoryService::class)->postAdjustment($this, Auth::id());
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (InventoryAdjustment $adjustment): void {
            $adjustment->adjustment_number ??= NumberSeries::getNextNumber(InventoryAdjustment::class);
            $adjustment->status ??= InventoryDocumentStatus::Draft;
        });

        static::created(function (InventoryAdjustment $adjustment): void {
            NumberSeries::incrementNextNumber(InventoryAdjustment::class);
        });
    }
}
