<?php

namespace App\Models;

use App\Enums\PromotionalSchemeAppliesTo;
use App\Enums\PromotionalSchemeStatus;
use App\Enums\PromotionalSchemeType;
use Database\Factories\PromotionalSchemeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionalScheme extends BaseModel
{
    /** @use HasFactory<PromotionalSchemeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'scheme_type',
        'status',
        'applies_to',
        'applies_to_id',
        'valid_from',
        'valid_to',
        'min_order_value',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'scheme_type' => PromotionalSchemeType::class,
            'status' => PromotionalSchemeStatus::class,
            'applies_to' => PromotionalSchemeAppliesTo::class,
            'valid_from' => 'date',
            'valid_to' => 'date',
            'min_order_value' => 'decimal:2',
        ];
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(PromotionalSchemeBenefit::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class);
    }

    public function scopeApplyTerritoryVisibility(Builder $query, array $territoryIds): Builder
    {
        return $query->where(function (Builder $target) use ($territoryIds): void {
            $target->where(fn (Builder $territory): Builder => $territory->where('applies_to', PromotionalSchemeAppliesTo::Territory)
                ->whereIn('applies_to_id', $territoryIds))
                ->orWhere(fn (Builder $customer): Builder => $customer->where('applies_to', PromotionalSchemeAppliesTo::Customer)
                    ->whereIn('applies_to_id', AccountMaster::query()->whereIn('territory_id', $territoryIds)->select('id')));
        });
    }

    public function isActive(): bool
    {
        if ($this->status !== PromotionalSchemeStatus::Active) {
            return false;
        }

        $today = now()->startOfDay();

        if ($this->valid_from !== null && $today->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_to !== null && $today->gt($this->valid_to)) {
            return false;
        }

        return true;
    }
}
