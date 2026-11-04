<?php

namespace App\Models;

use App\Services\Field\FieldSgipStockService;
use App\Services\Inventory\InventoryService;
use App\Services\SGIPComplianceService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SgipDistribution extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'employee_id',
        'account_master_id',
        'territory_id',
        'sales_tour_plan_id',
        'visit_id',
        'visit_date',
        'total_value',
        'approval_status',
        'sample_issue_id',
        'marketing_campaign_id',
        'inventory_source_location_id',
        'inventory_posted_at',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'total_value' => 'decimal:2',
            'inventory_posted_at' => 'datetime',
            'inventory_reserved_at' => 'datetime',
        ];
    }

    /* ============================
     | Relationships
     ============================ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'account_master_id');
    }

    public function accountMaster(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function tourPlan(): BelongsTo
    {
        return $this->belongsTo(SalesTourPlan::class, 'sales_tour_plan_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function sampleIssue(): BelongsTo
    {
        return $this->belongsTo(SampleIssue::class);
    }

    public function marketingCampaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class);
    }

    public function inventorySourceLocation(): BelongsTo
    {
        return $this->belongsTo(LocationMaster::class, 'inventory_source_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SgipDistributionItem::class);
    }

    public function violations(): HasMany
    {
        return $this->hasMany(SgipViolation::class);
    }

    /* ============================
     | Helpers
     ============================ */

    public function recalculateTotal(): void
    {
        $this->total_value = $this->items()->sum('total_value');
        $this->saveQuietly();
    }

    public function isInventoryPosted(): bool
    {
        return $this->inventory_posted_at !== null;
    }

    public function fieldVersion(): string
    {
        return hash('sha256', json_encode([
            $this->id, $this->updated_at?->toISOString(), $this->approval_status,
            $this->sample_issue_id, $this->marketing_campaign_id,
            $this->items()->orderBy('id')->get(['item_master_id', 'quantity', 'unit_value'])->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    public function approve(): void
    {
        app(InventoryService::class)->postSgipDistribution($this);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->getOriginal('inventory_reserved_at') && $this->isDirty('approval_status')
            && in_array($this->approval_status, ['draft', 'rejected', 'cancelled'], true)) {
            return $this->getConnection()->transaction(fn (): bool => parent::save($options));
        }

        return parent::save($options);
    }

    protected static function booted(): void
    {
        static::saving(function (SgipDistribution $distribution): void {
            if ($distribution->inventory_reserved_at && in_array($distribution->approval_status, ['draft', 'rejected', 'cancelled'], true)) {
                app(FieldSgipStockService::class)->release($distribution);
            }

            if (! $distribution->visit_date || ! $distribution->exists) {
                return;
            }

            if ($distribution->approval_status === 'draft') {
                SGIPComplianceService::validate($distribution, false);
            }
        });
    }
}
