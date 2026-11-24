<?php

namespace App\Models;

use App\Enums\MarketingCampaignStatus;
use Database\Factories\MarketingCampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends BaseModel
{
    /** @use HasFactory<MarketingCampaignFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_number',
        'name',
        'promotional_scheme_id',
        'status',
        'start_date',
        'end_date',
        'total_budget',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'status' => MarketingCampaignStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'total_budget' => 'decimal:2',
        ];
    }

    public function promotionalScheme(): BelongsTo
    {
        return $this->belongsTo(PromotionalScheme::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketingCampaignItem::class);
    }

    public function territoryQuotas(): HasMany
    {
        return $this->hasMany(MarketingCampaignTerritoryQuota::class);
    }

    public function sampleRequests(): HasMany
    {
        return $this->hasMany(SampleRequest::class, 'campaign_id');
    }

    public function sgipDistributions(): HasMany
    {
        return $this->hasMany(SgipDistribution::class);
    }

    public function scopeApplyTerritoryVisibility(Builder $query, array $territoryIds): Builder
    {
        return $query->whereHas('territoryQuotas', fn (Builder $quotas): Builder => $quotas->whereIn('territory_id', $territoryIds));
    }

    public function isActive(): bool
    {
        if ($this->status !== MarketingCampaignStatus::Active) {
            return false;
        }

        $today = now()->startOfDay();

        return $today->between($this->start_date, $this->end_date);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (MarketingCampaign $campaign): void {
            $campaign->campaign_number ??= NumberSeries::getNextNumber(MarketingCampaign::class);
            $campaign->status ??= MarketingCampaignStatus::Draft;
        });

        static::created(function (): void {
            NumberSeries::incrementNextNumber(MarketingCampaign::class);
        });
    }
}
