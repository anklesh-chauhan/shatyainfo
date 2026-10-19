<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelSegment extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'import_reference',
        'sales_dcr_id',
        'visit_id',
        'sales_tour_plan_detail_id',
        'patch_id',
        'from_account_id',
        'to_account_id',
        'from_area_town_id',
        'to_area_town_id',
        'transport_mode_id',
        'distance_km',
        'distance_source',
        'gps_distance_km',
        'is_auto_generated',
    ];

    protected $casts = [
        'distance_km' => 'decimal:2',
        'gps_distance_km' => 'decimal:2',
        'is_auto_generated' => 'boolean',
    ];

    public function salesDcr(): BelongsTo
    {
        return $this->belongsTo(SalesDcr::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function salesTourPlanDetail(): BelongsTo
    {
        return $this->belongsTo(SalesTourPlanDetail::class);
    }

    public function patch(): BelongsTo
    {
        return $this->belongsTo(Patch::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'to_account_id');
    }

    public function fromAreaTown(): BelongsTo
    {
        return $this->belongsTo(CityPinCode::class, 'from_area_town_id');
    }

    public function toAreaTown(): BelongsTo
    {
        return $this->belongsTo(CityPinCode::class, 'to_area_town_id');
    }

    public function transportMode(): BelongsTo
    {
        return $this->belongsTo(TransportMode::class, 'transport_mode_id');
    }
}
