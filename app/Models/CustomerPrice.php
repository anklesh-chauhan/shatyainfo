<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPrice extends BaseModel
{
    use HasApprovalWorkflow, HasFactory;

    protected $fillable = [
        'customer_id',
        'item_master_id',
        'price',
        'discount',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'item_master_id' => 'integer',
            'price' => 'decimal:2',
            'discount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'customer_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class, 'item_master_id');
    }

    public function scopeForRegister(Builder $query, ?User $actor = null): Builder
    {
        return $query->applyVisibility('CustomerPrice', $actor)->withinVisibleCustomers($actor);
    }

    public function scopeWithinVisibleCustomers(Builder $query, ?User $actor = null): Builder
    {
        return $query->whereIn(
            'customer_id',
            AccountMaster::query()->applyVisibility('AccountMaster', $actor)->select('id'),
        );
    }

    public function scopeApplyOwnVisibility(Builder $query, mixed $user): Builder
    {
        return $query->whereHas('customer', fn (Builder $customer): Builder => $customer->where('owner_id', $user->id));
    }

    public function scopeApplyTerritoryVisibility(Builder $query, array $territoryIds): Builder
    {
        return $query->whereHas('customer', fn (Builder $customer): Builder => $customer->whereIn('territory_id', $territoryIds));
    }

    /**
     * Accessors
     */
    public function getItemNameAttribute(): string
    {
        return $this->item?->item_name ?? '-';
    }

    public function getIsVariantAttribute(): bool
    {
        return ! is_null($this->item?->parent_id);
    }
}
